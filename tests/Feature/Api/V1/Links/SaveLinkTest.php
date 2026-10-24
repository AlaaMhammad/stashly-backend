<?php

namespace Tests\Feature\Api\V1\Links;

use App\Jobs\FetchLinkMetadata;
use App\Models\Link;
use App\Models\User;
use App\Services\Links\ContentTypeClassifier;
use App\Services\Links\LinkService;
use App\Services\Links\UrlNormalizer;
use App\Services\Tags\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API-LINK-002 POST /links (without Idempotency-Key; see IdempotencyTest).
 */
class SaveLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_saves_immediately_with_pending_metadata_and_contract_shape(): void
    {
        $this->travelTo('2026-10-16 09:30:12');

        $response = $this->postJson('/api/v1/links', [
            'url' => '  https://WWW.YouTube.com/watch?v=abc&utm_source=share#t=10  ',
            'note' => 'Watch later',
            'is_favorite' => true,
        ]);

        $response->assertCreated()
            ->assertExactJsonStructure(['data' => ['link' => [
                'id', 'original_url', 'normalized_url', 'source_domain', 'title', 'display_title', 'note',
                'is_favorite', 'content_type',
                'metadata' => ['status', 'title', 'description', 'site_name', 'favicon_url', 'preview_image_url', 'fetched_at', 'error_code'],
                'ai' => ['summary', 'tags', 'generated_at'],
                'collections', 'collections_count', 'tags', 'created_at', 'updated_at',
            ]]])
            ->assertJsonPath('data.link.original_url', 'https://WWW.YouTube.com/watch?v=abc&utm_source=share#t=10')
            ->assertJsonPath('data.link.normalized_url', 'https://youtube.com/watch?v=abc')
            ->assertJsonPath('data.link.source_domain', 'youtube.com')
            ->assertJsonPath('data.link.title', null)
            ->assertJsonPath('data.link.display_title', 'youtube.com')
            ->assertJsonPath('data.link.note', 'Watch later')
            ->assertJsonPath('data.link.is_favorite', true)
            ->assertJsonPath('data.link.content_type', 'video')
            ->assertJsonPath('data.link.metadata', [
                'status' => 'pending', 'title' => null, 'description' => null, 'site_name' => null,
                'favicon_url' => null, 'preview_image_url' => null, 'fetched_at' => null, 'error_code' => null,
            ])
            ->assertJsonPath('data.link.collections', [])
            ->assertJsonPath('data.link.collections_count', 0)
            ->assertJsonPath('data.link.tags', [])
            ->assertJsonPath('data.link.created_at', '2026-10-16T09:30:12Z')
            ->assertJsonPath('data.link.updated_at', '2026-10-16T09:30:12Z');

        $this->assertTrue(Str::isUlid($response->json('data.link.id')));
        $this->assertTrue(Link::sole()->user->is($this->user));
    }

    public function test_user_title_becomes_display_title(): void
    {
        $this->postJson('/api/v1/links', ['url' => 'example.com/a', 'title' => 'My title'])
            ->assertCreated()
            ->assertJsonPath('data.link.title', 'My title')
            ->assertJsonPath('data.link.display_title', 'My title');
    }

    public function test_empty_title_and_note_are_stored_as_null(): void
    {
        $this->postJson('/api/v1/links', ['url' => 'example.com/a', 'title' => '  ', 'note' => ''])
            ->assertCreated()
            ->assertJsonPath('data.link.title', null)
            ->assertJsonPath('data.link.note', null)
            ->assertJsonPath('data.link.is_favorite', false);
    }

    public function test_metadata_job_is_queued_with_the_link_id(): void
    {
        $id = $this->postJson('/api/v1/links', ['url' => 'example.com/a'])->json('data.link.id');

        Queue::assertPushed(FetchLinkMetadata::class, fn (FetchLinkMetadata $job) => $job->linkId === $id);
        Queue::assertPushed(FetchLinkMetadata::class, 1);
    }

    public function test_duplicate_returns_409_with_existing_link_and_queues_nothing(): void
    {
        $existing = $this->postJson('/api/v1/links', ['url' => 'https://www.example.com/post/', 'title' => 'First'])
            ->json('data.link');

        // Same page: different case, www, trailing slash, tracking and fragment.
        $response = $this->postJson('/api/v1/links', ['url' => 'HTTPS://example.com/post?utm_campaign=x#c', 'title' => 'Second']);

        $this->assertApiError($response, 409, 'DUPLICATE_LINK')
            ->assertJsonPath('error.details.existing_link', $existing);
        $this->assertSame(1, Link::count());
        Queue::assertPushed(FetchLinkMetadata::class, 1);
    }

    public function test_duplicate_detection_is_scoped_to_the_user(): void
    {
        $other = User::factory()->create();
        $theirs = $this->saveLink($other, 'https://example.com/shared', ['note' => 'private note']);

        $response = $this->postJson('/api/v1/links', ['url' => 'https://example.com/shared'])->assertCreated();

        $this->assertNotSame($theirs->id, $response->json('data.link.id'));
        $this->assertStringNotContainsString('private note', $response->getContent());
        $this->assertSame(2, Link::count());
    }

    public function test_concurrent_duplicate_caught_by_the_database_returns_409(): void
    {
        $existing = $this->saveLink($this->user, 'https://example.com/race');
        Queue::fake(); // reset the dispatch from the setup save

        // Simulate the race: the request's duplicate check runs before the other
        // save commits, so only the unique index can catch it.
        $service = Mockery::mock(LinkService::class, [app(UrlNormalizer::class), app(ContentTypeClassifier::class), app(TagService::class)])
            ->makePartial();
        $service->shouldReceive('findDuplicate')->andReturn(null, $existing);
        $this->instance(LinkService::class, $service);

        $response = $this->postJson('/api/v1/links', ['url' => 'https://example.com/race']);

        $this->assertApiError($response, 409, 'DUPLICATE_LINK')
            ->assertJsonPath('error.details.existing_link.id', $existing->id);
        $this->assertSame(1, Link::count());
        Queue::assertNothingPushed();
    }

    #[DataProvider('invalidUrls')]
    public function test_invalid_urls_return_invalid_url_and_save_nothing(string $url): void
    {
        $this->assertApiError($this->postJson('/api/v1/links', ['url' => $url]), 422, 'INVALID_URL');

        $this->assertSame(0, Link::count());
        Queue::assertNothingPushed();
    }

    public static function invalidUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'file' => ['file:///etc/passwd'],
            'data' => ['data:text/html,hi'],
            'intent' => ['intent://x#Intent;end'],
            'credentials' => ['https://user:pass@example.com'],
            'too long' => ['https://example.com/'.str_repeat('a', 2048)],
            'localhost' => ['http://localhost'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_fails_validation(array $payload, string $field): void
    {
        $response = $this->postJson('/api/v1/links', $payload);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => [$field]]]);
        $this->assertSame(0, Link::count());
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing url' => [[], 'url'],
            'non-string url' => [['url' => ['x']], 'url'],
            'title 301 chars' => [['url' => 'example.com', 'title' => str_repeat('t', 301)], 'title'],
            'note 2001 chars' => [['url' => 'example.com', 'note' => str_repeat('n', 2001)], 'note'],
            'non-boolean favorite' => [['url' => 'example.com', 'is_favorite' => 'maybe'], 'is_favorite'],
            'unknown collection' => [['url' => 'example.com', 'collection_ids' => ['01JAAAAAAAAAAAAAAAAAAAAAAA']], 'collection_ids.0'],
            'non-string tag' => [['url' => 'example.com', 'tags' => [['x']]], 'tags.0'],
            'empty tag' => [['url' => 'example.com', 'tags' => ['#  ']], 'tags.0'],
            'tag 41 chars' => [['url' => 'example.com', 'tags' => [str_repeat('t', 41)]], 'tags.0'],
        ];
    }

    public function test_boundary_lengths_and_empty_collections_and_tags_are_accepted(): void
    {
        $this->postJson('/api/v1/links', [
            'url' => 'example.com/limits',
            'title' => str_repeat('t', 300),
            'note' => str_repeat('n', 2000),
            'collection_ids' => [],
            'tags' => [],
        ])->assertCreated();
    }

    public function test_ownership_and_server_fields_cannot_be_injected(): void
    {
        $other = User::factory()->create();

        $this->postJson('/api/v1/links', [
            'url' => 'example.com/owned',
            'user_id' => $other->id,
            'normalized_url' => 'https://evil.example',
            'source_domain' => 'evil.example',
            'content_type' => 'product',
            'metadata_status' => 'ready',
        ])->assertCreated()
            ->assertJsonPath('data.link.normalized_url', 'https://example.com/owned')
            ->assertJsonPath('data.link.content_type', 'other')
            ->assertJsonPath('data.link.metadata.status', 'pending');

        $this->assertTrue(Link::sole()->user->is($this->user));
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->assertApiError($this->postJson('/api/v1/links', ['url' => 'example.com']), 401, 'UNAUTHENTICATED');
    }

    public function test_is_rate_limited_to_60_per_minute(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/links', ['url' => "example.com/$i"])->assertCreated();
        }

        $response = $this->postJson('/api/v1/links', ['url' => 'example.com/60']);
        $this->assertApiError($response, 429, 'RATE_LIMITED')->assertHeader('Retry-After');
    }
}
