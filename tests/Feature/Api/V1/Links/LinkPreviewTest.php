<?php

namespace Tests\Feature\Api\V1\Links;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
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
