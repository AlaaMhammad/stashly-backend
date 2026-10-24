<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API-TAG-001 GET /tags, API-SRCH-001 GET /search/filters, API-HOME-001 GET /home,
 * API-PROF-003 GET /me/stats. Counts must only ever include the user's own data.
 */
class OverviewEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create(['display_name' => 'Lina']);
        Sanctum::actingAs($this->user);

        // Noise that must never be counted.
        $stranger = User::factory()->create();
        $theirs = $stranger->collections()->create(['name' => 'Theirs']);
        $this->saveLink($stranger, 'https://youtube.com/watch?v=x', ['tags' => ['news'], 'collection_ids' => [$theirs->id], 'is_favorite' => true]);
    }

    public function test_tags_sorted_by_usage_then_name_with_counts(): void
    {
        $this->saveLink($this->user, 'https://example.com/1', ['tags' => ['news', 'tech']]);
        $this->saveLink($this->user, 'https://example.com/2', ['tags' => ['news', 'art']]);
        $this->saveLink($this->user, 'https://example.com/3', ['tags' => ['news']]);

        $this->getJson('/api/v1/tags')->assertOk()->assertExactJson(['data' => [
            ['name' => 'news', 'links_count' => 3],
            ['name' => 'art', 'links_count' => 1],
            ['name' => 'tech', 'links_count' => 1],
        ]]);

        $this->assertSame(['art', 'news', 'tech'], array_column($this->getJson('/api/v1/tags?sort=name')->json('data'), 'name'));
    }

    public function test_tags_prefix_search_and_limit(): void
    {
        $this->saveLink($this->user, 'https://example.com/1', ['tags' => ['news', 'newsletter', 'tech', 'a_b', 'axb']]);

        $this->assertSame(['news', 'newsletter'], array_column($this->getJson('/api/v1/tags?sort=name&q='.urlencode('#NE'))->json('data'), 'name'));
        $this->assertSame(['a_b'], array_column($this->getJson('/api/v1/tags?q=a_')->json('data'), 'name'));
        $this->assertCount(2, $this->getJson('/api/v1/tags?limit=2')->json('data'));
        $this->assertApiError($this->getJson('/api/v1/tags?limit=101'), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->getJson('/api/v1/tags?sort=random'), 422, 'VALIDATION_FAILED');
    }

    public function test_search_filters_report_counts_for_every_option(): void
    {
        $box = $this->user->collections()->create(['name' => 'Box']);
        $empty = $this->user->collections()->create(['name' => 'Empty']);
        $this->travelTo('2026-10-01 00:00:00');
        $this->saveLink($this->user, 'https://example.com/old', ['tags' => ['news']]);
        $this->travelTo('2026-10-20 00:00:00');
        $this->saveLink($this->user, 'https://youtube.com/watch?v=1', ['tags' => ['news', 'video'], 'collection_ids' => [$box->id]]);
        $this->saveLink($this->user, 'https://youtube.com/watch?v=2', ['collection_ids' => [$box->id]]);
        $this->travelTo('2026-10-22 00:00:00');

        $this->getJson('/api/v1/search/filters')->assertOk()->assertExactJson(['data' => [
            'content_types' => [
                ['value' => 'video', 'count' => 2],
                ['value' => 'article', 'count' => 0],
                ['value' => 'product', 'count' => 0],
                ['value' => 'social', 'count' => 0],
                ['value' => 'other', 'count' => 1],
            ],
            'sources' => [
                ['value' => 'youtube.com', 'count' => 2],
                ['value' => 'example.com', 'count' => 1],
            ],
            'collections' => [
                ['id' => $box->id, 'name' => 'Box', 'count' => 2],
                ['id' => $empty->id, 'name' => 'Empty', 'count' => 0],
            ],
            'tags' => [
                ['name' => 'news', 'count' => 2],
                ['name' => 'video', 'count' => 1],
            ],
            'date_presets' => [
                ['value' => 7, 'count' => 2],
                ['value' => 30, 'count' => 3],
            ],
        ]]);
    }

    public function test_home_aggregates_counts_quick_access_and_recent_links(): void
    {
        foreach (range(1, 12) as $i) {
            $this->travelTo('2026-10-01 00:00:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $this->saveLink($this->user, "https://example.com/$i", ['is_favorite' => $i <= 3]);
        }
        foreach (range(1, 5) as $i) {
            $this->travelTo("2026-10-02 00:00:0$i");
            $collections[] = $this->user->collections()->create(['name' => "C$i"]);
        }
        // Activity moves the oldest collection to the front.
        $this->travelTo('2026-10-03 00:00:00');
        $this->patchJson("/api/v1/collections/{$collections[0]->id}/links", ['detach' => [$this->user->links()->first()->id]])->assertOk();

        $response = $this->getJson('/api/v1/home')->assertOk()
            ->assertExactJsonStructure(['data' => ['user' => ['display_name'], 'links_count', 'favorites_count', 'quick_access', 'recent_links']])
            ->assertJsonPath('data.user.display_name', 'Lina')
            ->assertJsonPath('data.links_count', 12)
            ->assertJsonPath('data.favorites_count', 3)
            ->assertJsonCount(10, 'data.recent_links')
            ->assertJsonPath('data.recent_links.0.original_url', 'https://example.com/12');

        $this->assertSame(['C1', 'C5', 'C4', 'C3'], array_column($response->json('data.quick_access'), 'name'));
        $this->assertSame(['id', 'name', 'description', 'links_count', 'cover_images', 'is_public', 'share_url', 'created_at', 'updated_at'], array_keys($response->json('data.quick_access.0')));

        $this->getJson('/api/v1/home?recent_limit=20')->assertJsonCount(12, 'data.recent_links');
        $this->getJson('/api/v1/home?recent_limit=1')->assertJsonCount(1, 'data.recent_links');
        $this->assertApiError($this->getJson('/api/v1/home?recent_limit=21'), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->getJson('/api/v1/home?recent_limit=0'), 422, 'VALIDATION_FAILED');
    }

    public function test_home_for_a_new_account(): void
    {
        $this->getJson('/api/v1/home')->assertOk()->assertExactJson(['data' => [
            'user' => ['display_name' => 'Lina'],
            'links_count' => 0,
            'favorites_count' => 0,
            'quick_access' => [],
            'recent_links' => [],
        ]]);
    }

    public function test_me_stats(): void
    {
        $this->user->collections()->create(['name' => 'Box']);
        $this->saveLink($this->user, 'https://example.com/1', ['is_favorite' => true]);
        $this->saveLink($this->user, 'https://example.com/2');

        $this->getJson('/api/v1/me/stats')->assertOk()->assertExactJson(['data' => [
            'links_count' => 2,
            'collections_count' => 1,
            'favorites_count' => 1,
        ]]);
    }

    public function test_overview_endpoints_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        foreach (['/api/v1/tags', '/api/v1/search/filters', '/api/v1/home', '/api/v1/me/stats', '/api/v1/collections'] as $uri) {
            $this->assertApiError($this->getJson($uri), 401, 'UNAUTHENTICATED');
        }
    }
}
