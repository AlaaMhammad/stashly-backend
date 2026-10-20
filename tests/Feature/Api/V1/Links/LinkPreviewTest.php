<?php

namespace Tests\Feature\Api\V1\Links;

use App\Models\Link;
use App\Models\User;
use App\Services\Metadata\FetchFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeTransport;
use Tests\TestCase;

/**
 * API-LINK-001 POST /links/preview. Metadata fetching arrives with Phase 6.
 */
class LinkPreviewTest extends TestCase
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

    public function test_returns_normalized_url_and_contract_shape(): void
    {
        $this->postJson('/api/v1/links/preview', ['url' => ' youtu.be/abc?si=x&utm_source=y '])
            ->assertOk()
            ->assertExactJson(['data' => [
                'url' => 'youtu.be/abc?si=x&utm_source=y',
                'normalized_url' => 'https://youtu.be/abc?si=x',
                'source_domain' => 'youtu.be',
                'content_type' => 'video',
                'duplicate' => false,
                'existing_link' => null,
                'metadata' => null,
            ]]);
    }

    public function test_reports_an_existing_saved_link(): void
    {
        $link = $this->saveLink($this->user, 'https://example.com/post', ['title' => 'Saved']);

        $this->postJson('/api/v1/links/preview', ['url' => 'https://www.example.com/post/#x'])
            ->assertOk()
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.existing_link.id', $link->id)
            ->assertJsonPath('data.existing_link.title', 'Saved');
    }

    public function test_does_not_reveal_other_users_links(): void
    {
        $this->saveLink(User::factory()->create(), 'https://example.com/post', ['note' => 'private']);

        $response = $this->postJson('/api/v1/links/preview', ['url' => 'https://example.com/post'])
            ->assertOk()
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.existing_link', null);

        $this->assertStringNotContainsString('private', $response->getContent());
    }

    public function test_returns_live_metadata_found_within_the_preview_budget(): void
    {
        $this->transport->on('https://example.com/post', FakeTransport::html(
            '<title>Preview title</title><meta property="og:image" content="https://cdn.example.com/p.jpg"><meta property="og:type" content="article">',
        ));

        $response = $this->postJson('/api/v1/links/preview', ['url' => 'https://example.com/post?utm_source=x'])
            ->assertOk()
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.content_type', 'article')
            ->assertJsonPath('data.metadata.status', 'ready')
            ->assertJsonPath('data.metadata.title', 'Preview title')
            ->assertJsonPath('data.metadata.preview_image_url', 'https://cdn.example.com/p.jpg')
            ->assertJsonPath('data.metadata.error_code', null);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $response->json('data.metadata.fetched_at'));
        $this->assertSame(0, Link::count(), 'Preview never saves.');

        $timeout = $this->transport->requests[0]['timeout'];
        $this->assertLessThanOrEqual(4.0, $timeout, 'Preview is limited to 4 seconds.');
        $this->assertGreaterThan(3.5, $timeout);
    }

    #[DataProvider('failedLookups')]
    public function test_a_failed_lookup_gives_null_metadata_but_still_succeeds(callable $arrange): void
    {
        $arrange($this);

        $this->postJson('/api/v1/links/preview', ['url' => 'https://example.com/post'])
            ->assertOk()
            ->assertJsonPath('data.metadata', null)
            ->assertJsonPath('data.normalized_url', 'https://example.com/post')
            ->assertJsonPath('data.duplicate', false);
    }

    public static function failedLookups(): array
    {
        return [
            'timeout' => [fn ($t) => $t->transport->on('https://example.com/post', FetchFailure::timeout())],
            'not found' => [fn ($t) => $t->transport->on('https://example.com/post', FakeTransport::status(404))],
            'not html' => [fn ($t) => $t->transport->on('https://example.com/post', FakeTransport::html('{}', 200, 'application/json'))],
            'private address' => [fn ($t) => $t->dns->records['example.com'] = ['10.0.0.1']],
            'redirect to metadata service' => [fn ($t) => $t->transport->on('https://example.com/post', FakeTransport::redirect('http://169.254.169.254/'))],
            'unreachable' => [fn ($t) => null],
        ];
    }

    public function test_a_blocked_target_is_never_requested_by_the_preview(): void
    {
        $this->dns->records['example.com'] = ['127.0.0.1'];

        $this->postJson('/api/v1/links/preview', ['url' => 'https://example.com/post'])->assertOk()->assertJsonPath('data.metadata', null);

        $this->assertSame([], $this->transport->requests);
    }

    public function test_preview_of_an_ip_literal_in_the_private_range_is_blocked_not_rejected(): void
    {
        // The URL is syntactically valid, so the preview answers; the fetch is just refused.
        $this->postJson('/api/v1/links/preview', ['url' => 'http://169.254.169.254/latest/meta-data/'])
            ->assertOk()
            ->assertJsonPath('data.metadata', null);

        $this->assertSame([], $this->transport->requests);
    }

    public function test_a_saved_url_returns_the_saved_metadata_without_fetching(): void
    {
        $link = $this->saveLink($this->user, 'https://example.com/post');
        $link->forceFill(['metadata_status' => 'ready', 'metadata_title' => 'Saved title'])->save();

        $this->postJson('/api/v1/links/preview', ['url' => 'example.com/post'])
            ->assertOk()
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.metadata.title', 'Saved title')
            ->assertJsonPath('data.metadata.status', 'ready');

        $this->assertSame([], $this->transport->requests);
    }

    public function test_never_saves_or_queues_anything(): void
    {
        $this->postJson('/api/v1/links/preview', ['url' => 'example.com/a'])->assertOk();

        $this->assertSame(0, Link::count());
        Queue::assertNothingPushed();
    }

    public function test_invalid_url_returns_invalid_url(): void
    {
        $this->assertApiError($this->postJson('/api/v1/links/preview', ['url' => 'data:text/html,x']), 422, 'INVALID_URL');
        $this->assertApiError($this->postJson('/api/v1/links/preview', []), 422, 'VALIDATION_FAILED');
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->assertApiError($this->postJson('/api/v1/links/preview', ['url' => 'example.com']), 401, 'UNAUTHENTICATED');
    }

    public function test_is_rate_limited_to_20_per_minute(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/links/preview', ['url' => 'example.com'])->assertOk();
        }

        $this->assertApiError($this->postJson('/api/v1/links/preview', ['url' => 'example.com']), 429, 'RATE_LIMITED');
    }
}
