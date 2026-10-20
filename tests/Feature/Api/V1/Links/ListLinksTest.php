<?php

namespace Tests\Feature\Api\V1\Links;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API-LINK-003 GET /links: pagination, filters, search, ownership.
 */
class ListLinksTest extends TestCase
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

    /**
     * Save $count links, one second apart, oldest first.
     *
     * @return list<string> ids, oldest first
     */
    private function seedLinks(int $count, string $start = '2026-10-01 00:00:00'): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $this->travelTo(now()->parse($start)->addSeconds($i));
            $ids[] = $this->saveLink($this->user, "https://example.com/item/$i")->id;
        }
        $this->travelBack();

        return $ids;
    }

    private function ids($response): array
    {
        return array_column($response->json('data'), 'id');
    }

    public function test_lists_newest_first_with_default_page_size_and_meta(): void
    {
        $ids = $this->seedLinks(25);

        $response = $this->getJson('/api/v1/links')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'metadata', 'collections', 'tags']], 'meta' => ['per_page', 'has_more', 'next_cursor', 'total']])
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.total', 25);

        $this->assertSame(array_slice(array_reverse($ids), 0, 20), $this->ids($response));
    }

    public function test_cursor_walks_every_link_exactly_once_and_total_is_first_page_only(): void
    {
        $ids = $this->seedLinks(12);

        $first = $this->getJson('/api/v1/links?limit=5')->assertOk();
        $second = $this->getJson('/api/v1/links?limit=5&cursor='.$first->json('meta.next_cursor'))->assertOk();
        $third = $this->getJson('/api/v1/links?limit=5&cursor='.$second->json('meta.next_cursor'))->assertOk();

        $this->assertArrayNotHasKey('total', $second->json('meta'));
        $this->assertArrayNotHasKey('total', $third->json('meta'));
        $third->assertJsonPath('meta.has_more', false)->assertJsonPath('meta.next_cursor', null);

        $seen = array_merge($this->ids($first), $this->ids($second), $this->ids($third));
        $this->assertSame(array_reverse($ids), $seen);
    }

    public function test_links_saved_in_the_same_second_are_neither_skipped_nor_repeated(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $ids = [];
        for ($i = 0; $i < 7; $i++) {
            $ids[] = $this->saveLink($this->user, "https://example.com/same/$i")->id;
        }

        $seen = [];
        $cursor = null;
        do {
            $response = $this->getJson('/api/v1/links?limit=3'.($cursor ? "&cursor=$cursor" : ''))->assertOk();
            $seen = array_merge($seen, $this->ids($response));
            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null);

        $this->assertCount(7, $seen);
        $this->assertEqualsCanonicalizing($ids, $seen);
    }

    public function test_oldest_sort(): void
    {
        $ids = $this->seedLinks(4);

        $first = $this->getJson('/api/v1/links?sort=oldest&limit=2')->assertOk();
        $second = $this->getJson('/api/v1/links?sort=oldest&limit=2&cursor='.$first->json('meta.next_cursor'))->assertOk();

        $this->assertSame($ids, array_merge($this->ids($first), $this->ids($second)));
    }

    public function test_limit_bounds(): void
    {
        $this->seedLinks(3);

        $this->getJson('/api/v1/links?limit=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/links?limit=50')->assertOk()->assertJsonPath('meta.per_page', 50);

        foreach (['0', '51', 'abc', '-1'] as $limit) {
            $this->assertApiError($this->getJson("/api/v1/links?limit=$limit"), 422, 'VALIDATION_FAILED');
        }
    }

    public function test_only_the_users_own_links_are_listed(): void
    {
        $mine = $this->saveLink($this->user, 'https://example.com/mine');
        $this->saveLink(User::factory()->create(), 'https://example.com/theirs', ['note' => 'secret words']);

        $response = $this->getJson('/api/v1/links')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame([$mine->id], $this->ids($response));

        $this->getJson('/api/v1/links?q=secret')->assertOk()->assertJsonPath('meta.total', 0);
    }

    #[DataProvider('badCursors')]
    public function test_malformed_or_tampered_cursor_is_rejected(callable $mutate): void
    {
        $this->seedLinks(3);
        $cursor = $this->getJson('/api/v1/links?limit=1')->json('meta.next_cursor');

        $response = $this->getJson('/api/v1/links?limit=1&cursor='.urlencode($mutate($cursor)));

        $this->assertApiError($response, 422, 'INVALID_CURSOR');
    }

    public static function badCursors(): array
    {
        return [
            'garbage' => [fn (string $c) => 'not-a-cursor'],
            'truncated signature' => [fn (string $c) => substr($c, 0, -3)],
            'edited payload' => [fn (string $c) => 'A'.substr($c, 1)],
            'payload without signature' => [fn (string $c) => explode('.', $c)[0]],
        ];
    }

    public function test_cursor_used_with_different_filters_is_rejected(): void
    {
        $this->seedLinks(3);
        $cursor = $this->getJson('/api/v1/links?limit=1')->json('meta.next_cursor');

        $this->assertApiError($this->getJson("/api/v1/links?limit=1&favorite=true&cursor=$cursor"), 422, 'INVALID_CURSOR');
        $this->assertApiError($this->getJson("/api/v1/links?limit=1&sort=oldest&cursor=$cursor"), 422, 'INVALID_CURSOR');
        // Page size may change between pages.
        $this->getJson("/api/v1/links?limit=2&cursor=$cursor")->assertOk();
    }

    public function test_cursor_from_another_user_is_rejected(): void
    {
        $this->seedLinks(3);
        $cursor = $this->getJson('/api/v1/links?limit=1')->json('meta.next_cursor');

        Sanctum::actingAs(User::factory()->create());

        $this->assertApiError($this->getJson("/api/v1/links?limit=1&cursor=$cursor"), 422, 'INVALID_CURSOR');
    }

    public function test_favorite_filter(): void
    {
        $fav = $this->saveLink($this->user, 'https://example.com/fav', ['is_favorite' => true]);
        $plain = $this->saveLink($this->user, 'https://example.com/plain');

        $this->assertSame([$fav->id], $this->ids($this->getJson('/api/v1/links?favorite=true')));
        $this->assertSame([$fav->id], $this->ids($this->getJson('/api/v1/links?favorite=1')));
        $this->assertSame([$plain->id], $this->ids($this->getJson('/api/v1/links?favorite=false')));
        $this->assertApiError($this->getJson('/api/v1/links?favorite=yes'), 422, 'VALIDATION_FAILED');
    }

    public function test_values_within_one_filter_are_ored(): void
    {
        $video = $this->saveLink($this->user, 'https://youtube.com/watch?v=1');
        $social = $this->saveLink($this->user, 'https://x.com/post/1');
        $this->saveLink($this->user, 'https://example.com/other');

        $this->assertEqualsCanonicalizing(
            [$video->id, $social->id],
            $this->ids($this->getJson('/api/v1/links?content_type=video,social')),
        );
        $this->assertEqualsCanonicalizing(
            [$video->id, $social->id],
            $this->ids($this->getJson('/api/v1/links?source=youtube.com,%20X.com')),
        );
        $this->assertApiError($this->getJson('/api/v1/links?content_type=video,podcast'), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->getJson('/api/v1/links?content_type[]=video'), 422, 'VALIDATION_FAILED');
    }

    public function test_different_filters_are_anded(): void
    {
        $match = $this->saveLink($this->user, 'https://youtube.com/watch?v=1', ['is_favorite' => true]);
        $this->saveLink($this->user, 'https://youtube.com/watch?v=2');
        $this->saveLink($this->user, 'https://example.com/fav', ['is_favorite' => true]);

        $this->assertSame([$match->id], $this->ids($this->getJson('/api/v1/links?content_type=video&favorite=true')));
    }

    public function test_saved_within_days(): void
    {
        $this->travelTo('2026-10-01 00:00:00');
        $old = $this->saveLink($this->user, 'https://example.com/old');
        $this->travelTo('2026-10-20 00:00:00');
        $recent = $this->saveLink($this->user, 'https://example.com/recent');
        $this->travelTo('2026-10-25 00:00:00');

        $this->assertSame([$recent->id], $this->ids($this->getJson('/api/v1/links?saved_within_days=7')));
        $this->assertEqualsCanonicalizing([$old->id, $recent->id], $this->ids($this->getJson('/api/v1/links?saved_within_days=30')));
        $this->assertApiError($this->getJson('/api/v1/links?saved_within_days=14'), 422, 'VALIDATION_FAILED');
    }

    public function test_collection_filter_matches_any_of_the_given_collections(): void
    {
        $a = $this->user->collections()->create(['name' => 'A']);
        $b = $this->user->collections()->create(['name' => 'B']);
        $inA = $this->saveLink($this->user, 'https://example.com/a', ['collection_ids' => [$a->id]]);
        $inB = $this->saveLink($this->user, 'https://example.com/b', ['collection_ids' => [$b->id]]);
        $this->saveLink($this->user, 'https://example.com/unsorted');

        $this->assertSame([$inA->id], $this->ids($this->getJson("/api/v1/links?collection_id={$a->id}")));
        $this->assertEqualsCanonicalizing([$inA->id, $inB->id], $this->ids($this->getJson("/api/v1/links?collection_id={$a->id},".strtoupper($b->id))));
    }

    public function test_collection_filter_with_another_users_collection_matches_nothing(): void
    {
        $other = User::factory()->create();
        $theirs = $other->collections()->create(['name' => 'Theirs']);
        $this->saveLink($other, 'https://example.com/theirs', ['collection_ids' => [$theirs->id]]);
        $this->saveLink($this->user, 'https://example.com/mine');

        $this->getJson("/api/v1/links?collection_id={$theirs->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_several_tags_must_all_match(): void
    {
        $both = $this->saveLink($this->user, 'https://example.com/both', ['tags' => ['news', 'Tech']]);
        $newsOnly = $this->saveLink($this->user, 'https://example.com/news', ['tags' => ['news']]);
        $this->saveLink($this->user, 'https://example.com/none');

        $this->assertEqualsCanonicalizing([$both->id, $newsOnly->id], $this->ids($this->getJson('/api/v1/links?tags=news')));
        // Normalized like stored tags: case, leading # and spaces are ignored.
        $this->assertSame([$both->id], $this->ids($this->getJson('/api/v1/links?tags='.urlencode('#News, tech'))));
        $this->assertSame([], $this->ids($this->getJson('/api/v1/links?tags=news,missing')));
    }

    public function test_search_requires_every_word_across_all_searchable_fields(): void
    {
        $byTitle = $this->saveLink($this->user, 'https://example.com/1', ['title' => 'Laravel queues guide']);
        $byNote = $this->saveLink($this->user, 'https://example.com/2', ['note' => 'read about laravel later']);
        $byDomain = $this->saveLink($this->user, 'https://laravel-news.com/post');
        $byMetadata = $this->saveLink($this->user, 'https://example.com/3');
        $byMetadata->forceFill(['metadata_title' => 'Fetched Title', 'metadata_description' => 'all about LARAVEL'])->save();
        $this->saveLink($this->user, 'https://example.com/unrelated', ['title' => 'Cooking']);

        $this->assertEqualsCanonicalizing(
            [$byTitle->id, $byNote->id, $byDomain->id, $byMetadata->id],
            $this->ids($this->getJson('/api/v1/links?q=LARAVEL')),
        );
        // Every word must match (in any of the fields).
        $this->assertSame([$byTitle->id], $this->ids($this->getJson('/api/v1/links?q=laravel%20guide')));
        $this->assertSame([$byMetadata->id], $this->ids($this->getJson('/api/v1/links?q=fetched%20laravel')));
        $this->assertSame([], $this->ids($this->getJson('/api/v1/links?q=laravel%20cooking')));
    }

    public function test_search_is_arabic_aware(): void
    {
        $voweled = $this->saveLink($this->user, 'https://example.com/a', ['title' => 'مُحَمَّد وَ أَحْمَد']);
        $plain = $this->saveLink($this->user, 'https://example.com/b', ['note' => 'كتاب إسلام']);

        $this->assertSame([$voweled->id], $this->ids($this->getJson('/api/v1/links?q='.urlencode('محمد احمد'))));
        $this->assertSame([$plain->id], $this->ids($this->getJson('/api/v1/links?q='.urlencode('كِتَاب اسلام'))));
        $this->assertSame([$plain->id], $this->ids($this->getJson('/api/v1/links?q='.urlencode('آسلام'))));
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $percent = $this->saveLink($this->user, 'https://example.com/a', ['title' => '100% off']);
        $this->saveLink($this->user, 'https://example.com/b', ['title' => 'plain']);
        $underscore = $this->saveLink($this->user, 'https://example.com/c', ['title' => 'snake_case']);

        $this->assertSame([$percent->id], $this->ids($this->getJson('/api/v1/links?q='.urlencode('%'))));
        $this->assertSame([$underscore->id], $this->ids($this->getJson('/api/v1/links?q=_')));
        $this->assertSame([], $this->ids($this->getJson('/api/v1/links?q='.urlencode('!'))));
    }

    public function test_search_text_is_kept_in_sync_when_a_link_is_edited(): void
    {
        $link = $this->saveLink($this->user, 'https://example.com/a', ['title' => 'before']);

        $this->patchJson("/api/v1/links/{$link->id}", ['title' => 'after'])->assertOk();

        $this->assertSame([], $this->ids($this->getJson('/api/v1/links?q=before')));
        $this->assertSame([$link->id], $this->ids($this->getJson('/api/v1/links?q=after')));
    }

    public function test_search_cursor_pages_through_matches(): void
    {
        foreach (range(1, 5) as $i) {
            $this->travelTo("2026-10-01 00:00:0$i");
            $this->saveLink($this->user, "https://example.com/m$i", ['title' => "match $i"]);
            $this->saveLink($this->user, "https://example.com/x$i", ['title' => "other $i"]);
        }

        $first = $this->getJson('/api/v1/links?q=match&limit=3')->assertJsonPath('meta.total', 5);
        $second = $this->getJson('/api/v1/links?q=match&limit=3&cursor='.$first->json('meta.next_cursor'));

        $titles = array_column(array_merge($first->json('data'), $second->json('data')), 'title');
        $this->assertSame(['match 5', 'match 4', 'match 3', 'match 2', 'match 1'], $titles);
        $this->assertSame(10, Link::count());
    }
}
