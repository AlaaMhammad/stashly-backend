<?php

namespace Tests\Feature\Api\V1\Links;

use App\Jobs\FetchLinkMetadata;
use App\Models\IdempotencyRecord;
use App\Models\Link;
use App\Models\User;
use App\Services\Links\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /links Idempotency-Key (contract 1.1, SEC-020; provisional rules DEC-005).
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '5f0c6d1e-7b2a-4c3d-9e8f-0a1b2c3d4e5f';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function save(array $body, string $key = self::KEY)
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/links', $body);
    }

    public function test_repeat_within_24_hours_returns_the_first_response_without_a_second_save(): void
    {
        $first = $this->save(['url' => 'example.com/a', 'title' => 'T'])->assertCreated();

        $this->travel(23)->hours();
        $second = $this->save(['url' => 'example.com/a', 'title' => 'T']);

        $second->assertCreated();
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, Link::count());
        Queue::assertPushed(FetchLinkMetadata::class, 1);
    }

    public function test_body_key_order_does_not_change_the_fingerprint(): void
    {
        $first = $this->save(['url' => 'example.com/a', 'title' => 'T']);
        $second = $this->save(['title' => 'T', 'url' => 'example.com/a']);

        $this->assertSame($first->getContent(), $second->getContent());
    }

    public function test_key_is_case_insensitive(): void
    {
        $first = $this->save(['url' => 'example.com/a']);
        $second = $this->save(['url' => 'example.com/a'], strtoupper(self::KEY));

        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(1, Link::count());
    }

    public function test_same_key_with_a_different_body_is_rejected_without_processing(): void
    {
        $this->save(['url' => 'example.com/a'])->assertCreated();

        $response = $this->save(['url' => 'example.com/b']);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['idempotency_key']]]);
        $this->assertSame(1, Link::count());
    }

    public function test_key_must_be_a_uuid(): void
    {
        $response = $this->save(['url' => 'example.com/a'], 'not-a-uuid');

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['idempotency_key']]]);
        $this->assertSame(0, Link::count());
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $this->save(['url' => 'example.com/a'])->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $this->save(['url' => 'example.com/a'])->assertCreated();

        $this->assertSame(2, Link::count());
    }

    public function test_after_24_hours_the_key_is_processed_again(): void
    {
        $this->save(['url' => 'example.com/a'])->assertCreated();

        $this->travel(25)->hours();

        // Processed fresh: the link already exists, so this is a duplicate, not a replayed 201.
        $this->assertApiError($this->save(['url' => 'example.com/a']), 409, 'DUPLICATE_LINK');
    }

    public function test_duplicate_outcome_is_replayed_even_if_the_link_is_later_deleted(): void
    {
        $this->saveLink($this->user, 'https://example.com/a');

        $first = $this->save(['url' => 'example.com/a']);
        $this->assertApiError($first, 409, 'DUPLICATE_LINK');

        Link::query()->delete();
        $second = $this->save(['url' => 'example.com/a']);

        $this->assertSame(409, $second->status());
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(0, Link::count());
    }

    public function test_rejected_requests_are_not_stored_so_the_key_can_be_retried(): void
    {
        $this->assertApiError($this->save(['url' => 'javascript:alert(1)']), 422, 'INVALID_URL');
        $this->assertApiError($this->save(['url' => 'example.com', 'title' => str_repeat('t', 301)]), 422, 'VALIDATION_FAILED');

        $this->save(['url' => 'example.com/fixed'])->assertCreated();
        $this->assertSame(1, IdempotencyRecord::count());
    }

    public function test_service_stores_only_created_and_duplicate_outcomes(): void
    {
        $service = app(IdempotencyService::class);
        $calls = 0;
        $failing = function () use (&$calls) {
            $calls++;

            return new JsonResponse(['error' => ['code' => 'SERVER_ERROR']], 500);
        };

        $service->run($this->user, self::KEY, ['url' => 'x'], $failing);
        $service->run($this->user, self::KEY, ['url' => 'x'], $failing);

        $this->assertSame(2, $calls, 'A 500 must not be replayed; the retry has to be processed.');
        $this->assertSame(0, IdempotencyRecord::count());
    }

    public function test_concurrent_request_with_the_same_key_is_not_processed_twice(): void
    {
        config(['stashly.idempotency.lock_wait_seconds' => 0]);

        // Another request with this key holds the lock.
        $lock = Cache::lock('idempotency:'.$this->user->id.':'.self::KEY, 30);
        $this->assertTrue($lock->get());

        $response = $this->save(['url' => 'example.com/a']);

        $this->assertApiError($response, 503, 'SERVICE_UNAVAILABLE')->assertHeader('Retry-After', '1');
        $this->assertSame(0, Link::count());

        $lock->release();
        $this->save(['url' => 'example.com/a'])->assertCreated();
    }

    public function test_requests_without_a_key_are_not_deduplicated_by_idempotency(): void
    {
        $this->postJson('/api/v1/links', ['url' => 'example.com/a'])->assertCreated();
        $this->assertApiError($this->postJson('/api/v1/links', ['url' => 'example.com/a']), 409, 'DUPLICATE_LINK');

        $this->assertSame(0, IdempotencyRecord::count());
    }

    public function test_expired_records_are_pruned_by_the_scheduled_command(): void
    {
        $this->save(['url' => 'example.com/a']);
        $this->travel(25)->hours();
        $this->save(['url' => 'example.com/b'], '0b8f1c2d-3e4f-4a5b-8c6d-7e8f9a0b1c2d');

        $this->artisan('model:prune', ['--model' => [IdempotencyRecord::class]])->assertSuccessful();

        $this->assertSame(['0b8f1c2d-3e4f-4a5b-8c6d-7e8f9a0b1c2d'], IdempotencyRecord::pluck('key')->all());
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('model:prune')->assertSuccessful();
    }
}
