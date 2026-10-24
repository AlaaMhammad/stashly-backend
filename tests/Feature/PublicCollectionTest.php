<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Link;
use App\Models\User;
use App\Services\Collections\CollectionSharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-COLL-007 POST /collections/{collection}/share and API-PUB-001 GET /public/collections/{slug} (DEC-041).
 */
class PublicCollectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['display_name' => 'Lina', 'email' => 'lina.private@example.test']);
        $this->token = $this->tokenFor($this->user);
        $this->collection = $this->user->collections()->create(['name' => 'Laravel Reading List', 'description' => 'Good reads']);
    }

    private function share(bool $public, ?Collection $collection = null, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->postJson('/api/v1/collections/'.($collection ?? $this->collection)->id.'/share', ['is_public' => $public]);
    }

    private function publish(?Collection $collection = null): string
    {
        $slug = $this->share(true, $collection)->json('data.share_slug');
        $this->resetAuth();
        $this->flushHeaders();

        return $slug;
    }

    private function inCollection(string $url, array $data = [], ?Collection $collection = null): Link
    {
        $link = $this->saveLink($this->user, $url, $data);
        ($collection ?? $this->collection)->links()->attach($link->id);

        return $link;
    }

    // ------------------------------------------------------------------ the toggle

    public function test_sharing_creates_a_readable_unguessable_slug_and_url(): void
    {
        $response = $this->share(true)->assertOk();

        $slug = $response->json('data.share_slug');
        $this->assertMatchesRegularExpression('/^laravel-reading-list-[a-z0-9]{10}$/', $slug);
        $response->assertExactJson(['data' => [
            'is_public' => true,
            'share_slug' => $slug,
            'share_url' => url('/api/v1/public/collections/'.$slug),
        ]]);
        $this->assertTrue($this->collection->fresh()->is_public);
    }

    public function test_enabling_again_returns_the_same_address(): void
    {
        $first = $this->share(true)->json('data.share_slug');
        $this->resetAuth();
        $second = $this->share(true)->json('data.share_slug');

        $this->assertSame($first, $second);
        $this->assertSame(1, Collection::where('share_slug', $first)->count());
    }

    public function test_switching_off_hides_the_address_and_switching_on_restores_it(): void
    {
        $slug = $this->share(true)->json('data.share_slug');
        $this->resetAuth();

        $this->share(false)->assertOk()->assertExactJson(['data' => ['is_public' => false, 'share_slug' => null, 'share_url' => null]]);
        $this->assertFalse($this->collection->fresh()->is_public);
        $this->resetAuth();

        $this->assertSame($slug, $this->share(true)->json('data.share_slug'));
    }

    public function test_switching_off_something_never_shared_is_fine(): void
    {
        $this->share(false)->assertOk()->assertJsonPath('data.is_public', false);
        $this->assertNull($this->collection->fresh()->share_slug);
    }

    public function test_the_payload_is_validated(): void
    {
        foreach ([null, 'maybe', ['x'], ''] as $bad) {
            $this->assertApiError($this->withToken($this->token)->postJson('/api/v1/collections/'.$this->collection->id.'/share', ['is_public' => $bad]), 422, 'VALIDATION_FAILED');
        }
        $this->assertApiError($this->withToken($this->token)->postJson('/api/v1/collections/'.$this->collection->id.'/share'), 422, 'VALIDATION_FAILED');
        $this->assertFalse($this->collection->fresh()->is_public);
    }

    public function test_only_the_owner_can_change_sharing(): void
    {
        $stranger = User::factory()->create();
        $strangerToken = $this->tokenFor($stranger);

        $this->assertApiError($this->share(true, null, $strangerToken), 404, 'NOT_FOUND');
        $this->assertApiError($this->withToken($this->token)->postJson('/api/v1/collections/01jc3k9m4x7v2b8n5q6r0s1t2w/share', ['is_public' => true]), 404, 'NOT_FOUND');
        $this->assertApiError($this->withToken($this->token)->postJson('/api/v1/collections/nope/share', ['is_public' => true]), 404, 'NOT_FOUND');
        $this->resetAuth();
        $this->flushHeaders();
        $this->assertApiError($this->postJson('/api/v1/collections/'.$this->collection->id.'/share', ['is_public' => true]), 401, 'UNAUTHENTICATED');

        $this->assertFalse($this->collection->fresh()->is_public);
        $this->assertNull($this->collection->fresh()->share_slug);
    }

    public function test_the_collection_objects_report_the_sharing_state(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/collections/'.$this->collection->id)
            ->assertJsonPath('data.collection.is_public', false)->assertJsonPath('data.collection.share_url', null);
        $this->resetAuth();

        $slug = $this->share(true)->json('data.share_slug');
        $this->resetAuth();

        $this->withToken($this->token)->getJson('/api/v1/collections')
            ->assertJsonPath('data.0.is_public', true)
            ->assertJsonPath('data.0.share_url', url('/api/v1/public/collections/'.$slug));
    }

    // ------------------------------------------------------------------ slugs

    public function test_slug_collisions_are_retried(): void
    {
        $other = $this->user->collections()->create(['name' => 'Other']);
        $other->forceFill(['share_slug' => 'taken-slug-1', 'is_public' => true])->save();

        $this->app->instance(CollectionSharingService::class, new class extends CollectionSharingService
        {
            /** @var list<string> */
            public array $slugs = ['taken-slug-1', 'fresh-slug-2'];

            public function slug(string $name): string
            {
                return array_shift($this->slugs);
            }
        });

        $this->share(true)->assertOk()->assertJsonPath('data.share_slug', 'fresh-slug-2');
        $this->assertSame('taken-slug-1', $other->fresh()->share_slug, 'The other collection keeps its address.');
    }

    public function test_equal_names_get_different_slugs(): void
    {
        $other = User::factory()->create();
        $twin = $other->collections()->create(['name' => 'Laravel Reading List']);

        $a = $this->share(true)->json('data.share_slug');
        $b = $this->share(true, $twin, $this->tokenFor($other))->json('data.share_slug');

        $this->assertNotSame($a, $b);
    }

    public function test_slugs_are_safe_for_any_name(): void
    {
        $service = new CollectionSharingService;

        foreach (['مجموعة القراءة', '😀😀', '  ---  ', 'Café & Crème!', str_repeat('very long name ', 20)] as $name) {
            $slug = $service->slug($name);
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug, $name);
            $this->assertLessThanOrEqual(80, strlen($slug));
        }
        $this->assertStringStartsWith('collection-', $service->slug('😀😀'));
        $this->assertStringStartsWith('cafe-creme', $service->slug('Café & Crème!'));
    }

    // ------------------------------------------------------------------ the public view

    public function test_anyone_can_read_a_public_collection_without_a_token(): void
    {
        $slug = $this->publish();
        $older = $this->inCollection('https://example.com/older', ['title' => 'Older post']);
        Link::query()->whereKey($older->id)->update(['created_at' => now()->subDay()]);
        $newer = $this->inCollection('https://example.com/newer?utm_source=x', ['title' => 'Newer post']);
        $newer->forceFill(['metadata_description' => 'A public description'])->save();
        $this->saveLink($this->user, 'https://example.com/not-in-collection', ['title' => 'Elsewhere']);

        $response = $this->getJson("/api/v1/public/collections/$slug")->assertOk();

        $response->assertExactJson(['data' => [
            'collection' => [
                'name' => 'Laravel Reading List',
                'description' => 'Good reads',
                'owner' => ['display_name' => 'Lina'],
                'links_count' => 2,
                'updated_at' => $response->json('data.collection.updated_at'),
            ],
            'links' => [
                ['title' => 'Newer post', 'url' => 'https://example.com/newer', 'description' => 'A public description', 'created_at' => $response->json('data.links.0.created_at')],
                ['title' => 'Older post', 'url' => 'https://example.com/older', 'description' => null, 'created_at' => $response->json('data.links.1.created_at')],
            ],
        ], 'meta' => ['per_page' => 20, 'has_more' => false, 'next_cursor' => null, 'total' => 2]]);
        $response->assertHeader('X-Robots-Tag', 'noindex');
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    public function test_nothing_private_is_exposed(): void
    {
        $slug = $this->publish();
        $link = $this->inCollection('https://example.com/page', ['title' => 'Public title', 'note' => 'MY PRIVATE NOTE', 'tags' => ['secret-tag'], 'is_favorite' => true]);
        $link->forceFill(['ai_summary' => 'PRIVATE AI SUMMARY', 'ai_tags' => ['ai-secret'], 'ai_insights' => ['x' => 'INSIGHT-SECRET'], 'ai_action_plan' => ['objective' => 'PLAN-SECRET']])->save();

        $body = $this->getJson("/api/v1/public/collections/$slug")->assertOk()->getContent();

        foreach (['MY PRIVATE NOTE', 'secret-tag', 'PRIVATE AI SUMMARY', 'ai-secret', 'INSIGHT-SECRET', 'PLAN-SECRET', 'lina.private@example.test',
            $this->user->id, $link->id, $this->collection->id, $this->token, 'is_favorite', 'user_id', 'email', $slug, 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
    }

    public function test_another_collection_of_the_same_user_is_not_exposed(): void
    {
        $slug = $this->publish();
        $private = $this->user->collections()->create(['name' => 'Private stuff']);
        $this->inCollection('https://example.com/hidden', ['title' => 'Hidden link'], $private);

        $body = $this->getJson("/api/v1/public/collections/$slug")->assertOk()->getContent();

        $this->assertStringNotContainsString('Hidden link', $body);
        $this->assertStringNotContainsString('Private stuff', $body);
    }

    public function test_unknown_private_and_switched_off_slugs_all_look_the_same(): void
    {
        $slug = $this->publish();

        $unknown = $this->assertApiError($this->getJson('/api/v1/public/collections/does-not-exist-abc'), 404, 'NOT_FOUND');
        $this->collection->forceFill(['is_public' => false])->save();
        $off = $this->assertApiError($this->getJson("/api/v1/public/collections/$slug"), 404, 'NOT_FOUND');

        $this->assertSame($unknown->json('error.message'), $off->json('error.message'));
        $this->assertApiError($this->getJson('/api/v1/public/collections/bad_slug!'), 404, 'NOT_FOUND');
        $this->assertApiError($this->getJson('/api/v1/public/collections/'.str_repeat('a', 81)), 404, 'NOT_FOUND');
    }

    public function test_sharing_off_takes_effect_at_once(): void
    {
        $slug = $this->publish();
        $this->getJson("/api/v1/public/collections/$slug")->assertOk();

        $this->share(false)->assertOk();
        $this->resetAuth();
        $this->flushHeaders();

        $this->assertApiError($this->getJson("/api/v1/public/collections/$slug"), 404, 'NOT_FOUND');
    }

    public function test_the_slug_is_case_insensitive(): void
    {
        $slug = $this->publish();

        $this->getJson('/api/v1/public/collections/'.strtoupper($slug))->assertOk();
    }

    public function test_the_view_follows_changes_to_the_collection(): void
    {
        $slug = $this->publish();
        $link = $this->inCollection('https://example.com/a', ['title' => 'A']);
        $this->getJson("/api/v1/public/collections/$slug")->assertJsonPath('meta.total', 1);

        $this->withToken($this->token)->deleteJson('/api/v1/links/'.$link->id)->assertNoContent();
        $this->resetAuth();
        $this->flushHeaders();
        $this->getJson("/api/v1/public/collections/$slug")->assertJsonPath('meta.total', 0)->assertJsonPath('data.links', []);

        $this->withToken($this->token)->patchJson('/api/v1/collections/'.$this->collection->id, ['name' => 'Renamed'])->assertOk();
        $this->resetAuth();
        $this->flushHeaders();
        $this->getJson("/api/v1/public/collections/$slug")->assertJsonPath('data.collection.name', 'Renamed');
    }

    public function test_deleting_the_collection_or_the_account_removes_the_public_page(): void
    {
        $slug = $this->publish();
        $second = $this->user->collections()->create(['name' => 'Second']);
        $slug2 = $this->publish($second);

        $this->withToken($this->token)->deleteJson('/api/v1/collections/'.$this->collection->id)->assertNoContent();
        $this->resetAuth();
        $this->flushHeaders();
        $this->assertApiError($this->getJson("/api/v1/public/collections/$slug"), 404, 'NOT_FOUND');

        $this->withToken($this->token)->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();
        $this->resetAuth();
        $this->flushHeaders();
        $this->assertApiError($this->getJson("/api/v1/public/collections/$slug2"), 404, 'NOT_FOUND');
    }

    public function test_the_feed_is_paginated_with_a_cursor_bound_to_that_collection(): void
    {
        $slug = $this->publish();
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $link = $this->inCollection("https://example.com/p$i", ['title' => "P$i"]);
            Link::query()->whereKey($link->id)->update(['created_at' => now()->subHours(10 - $i)]);
            $ids[] = "P$i";
        }

        $first = $this->getJson("/api/v1/public/collections/$slug?limit=2")->assertOk()->assertJsonPath('meta.has_more', true)->assertJsonPath('meta.total', 5);
        $second = $this->getJson("/api/v1/public/collections/$slug?limit=2&cursor=".urlencode($first->json('meta.next_cursor')))->assertOk()->assertJsonMissingPath('meta.total');
        $third = $this->getJson("/api/v1/public/collections/$slug?limit=2&cursor=".urlencode($second->json('meta.next_cursor')))->assertOk()->assertJsonPath('meta.has_more', false);

        $titles = array_merge(array_column($first->json('data.links'), 'title'), array_column($second->json('data.links'), 'title'), array_column($third->json('data.links'), 'title'));
        $this->assertSame(array_reverse($ids), $titles);

        // The collection header repeats on every page; a cursor of another collection is refused.
        $other = $this->user->collections()->create(['name' => 'Other']);
        $otherSlug = $this->publish($other);
        $this->assertApiError($this->getJson("/api/v1/public/collections/$otherSlug?cursor=".urlencode($first->json('meta.next_cursor'))), 422, 'INVALID_CURSOR');
        $this->assertApiError($this->getJson("/api/v1/public/collections/$slug?limit=0"), 422, 'VALIDATION_FAILED');
    }

    public function test_the_public_feed_is_limited_per_ip(): void
    {
        config(['stashly.rate_limits.public_feed_per_minute' => 3]);
        $slug = $this->publish();

        for ($i = 0; $i < 3; $i++) {
            $this->getJson("/api/v1/public/collections/$slug")->assertOk();
        }

        $this->assertApiError($this->getJson("/api/v1/public/collections/$slug"), 429, 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->getJson("/api/v1/public/collections/$slug")->assertOk();
    }
}
