<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-LINK-012 GET /links/search (DEC-041).
 */
class AdvancedSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    /** @var array<string, Link> */
    private array $l = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->tokenFor($this->user);
        $dev = $this->user->collections()->create(['name' => 'Dev']);
        $food = $this->user->collections()->create(['name' => 'Food']);

        // name => [url, data, created_at]
        $rows = [
            'queues' => ['https://laravel.com/queues', ['title' => 'Laravel queues', 'note' => 'worker setup', 'is_favorite' => true, 'tags' => ['php', 'laravel'], 'collection_ids' => [$dev->id]], '2026-01-10 12:00:00'],
            'pasta' => ['https://cooking.example/pasta', ['title' => 'Perfect pasta', 'tags' => ['recipe'], 'collection_ids' => [$food->id]], '2026-02-15 08:00:00'],
            'arabic' => ['https://example.com/ar', ['title' => 'مقال عن البرمجة', 'note' => 'ملاحظة', 'tags' => ['php']], '2026-03-01 00:00:00'],
            'zebra' => ['https://zoo.example/zebra', ['title' => 'Zebra facts', 'is_favorite' => true], '2026-03-20 23:59:59'],
            'alpha' => ['https://example.com/alpha', ['title' => 'alpha notes', 'collection_ids' => [$dev->id, $food->id]], '2026-04-05 10:30:00'],
        ];
        foreach ($rows as $name => [$url, $data, $created]) {
            $link = $this->saveLink($this->user, $url, $data);
            Link::query()->whereKey($link->id)->update(['created_at' => $created]);
            $this->l[$name] = $link;
        }
        $this->l['queues']->forceFill(['metadata_description' => 'Background jobs in the framework', 'ai_summary' => '- explains workers and retries'])->save();
        $this->l['pasta']->forceFill(['metadata_description' => 'Cook al dente'])->save();
        Link::query()->whereKey($this->l['queues']->id)->update(['created_at' => '2026-01-10 12:00:00']);
        Link::query()->whereKey($this->l['pasta']->id)->update(['created_at' => '2026-02-15 08:00:00']);
    }

    private function search(string $query = '', ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->getJson('/api/v1/links/search'.($query === '' ? '' : '?'.$query));
    }

    /** @return list<string> link names in result order */
    private function names(string $query): array
    {
        $this->resetAuth();
        $ids = array_column($this->search($query)->assertOk()->json('data'), 'id');
        $byId = [];
        foreach ($this->l as $name => $link) {
            $byId[$link->id] = $name;
        }

        return array_map(fn ($id) => $byId[$id], $ids);
    }

    // ------------------------------------------------------------------ query

    public function test_no_filters_lists_everything_newest_first_with_the_list_envelope(): void
    {
        $response = $this->search()->assertOk()
            ->assertJsonStructure(['data' => [['id', 'title', 'ai', 'health', 'collections', 'tags']], 'meta' => ['per_page', 'has_more', 'next_cursor', 'total']])
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 20);

        $this->assertSame(['alpha', 'zebra', 'arabic', 'pasta', 'queues'], $this->names(''));
        $this->assertNotNull($response);
    }

    public function test_query_searches_title_description_note_summary_and_domain(): void
    {
        $this->assertSame(['queues'], $this->names('query=queues'), 'title');
        $this->assertSame(['queues'], $this->names('query=framework'), 'description');
        $this->assertSame(['queues'], $this->names('query=worker'), 'note and ai_summary');
        $this->assertSame(['queues'], $this->names('query=retries'), 'ai_summary only');
        $this->assertSame(['pasta'], $this->names('query=dente'), 'description');
        $this->assertSame(['zebra'], $this->names('query=zoo.example'), 'domain');
    }

    public function test_query_is_case_insensitive_all_words_must_match_and_arabic_is_normalized(): void
    {
        $this->assertSame(['queues'], $this->names('query=LARAVEL+Queues'));
        $this->assertSame([], $this->names('query=laravel+pasta'));
        $this->assertSame([], $this->names('query='.urlencode('البرمجه')), 'Teh marbuta and heh are different letters: not folded.');
        $this->assertSame(['arabic'], $this->names('query='.urlencode('البَرمجة')), 'Diacritics are ignored.');
        $this->assertSame(['arabic'], $this->names('query='.urlencode('البرمجة')));
        $this->assertSame(['arabic'], $this->names('query='.urlencode('ملاحظة')));
    }

    public function test_wildcard_characters_in_the_query_are_literal(): void
    {
        $this->assertSame([], $this->names('query=%25'));
        $this->assertSame([], $this->names('query=_'));
        $this->assertCount(5, $this->l);
    }

    public function test_a_new_ai_summary_becomes_searchable(): void
    {
        $this->assertSame([], $this->names('query=hallucinations'));
        $this->l['zebra']->forceFill(['ai_summary' => '- about hallucinations'])->save();

        $this->assertSame(['zebra'], $this->names('query=hallucinations'));
    }

    // ------------------------------------------------------------------ single filters

    public function test_collection_filter(): void
    {
        $dev = $this->user->collections()->where('name', 'Dev')->value('id');
        $food = $this->user->collections()->where('name', 'Food')->value('id');

        $this->assertEqualsCanonicalizing(['queues', 'alpha'], $this->names("collection_id=$dev"));
        $this->assertEqualsCanonicalizing(['pasta', 'alpha'], $this->names("collection_id=$food"));
        $this->assertSame([], $this->names('collection_id=01jc3k9m4x7v2b8n5q6r0s1t2w'));
    }

    public function test_another_users_collection_matches_nothing(): void
    {
        $theirs = User::factory()->create()->collections()->create(['name' => 'Theirs']);

        $this->assertSame([], $this->names('collection_id='.$theirs->id));
    }

    public function test_tag_filter_is_normalized(): void
    {
        $this->assertEqualsCanonicalizing(['queues', 'arabic'], $this->names('tag=php'));
        $this->assertEqualsCanonicalizing(['queues', 'arabic'], $this->names('tag='.urlencode(' #PHP ')));
        $this->assertSame(['pasta'], $this->names('tag=recipe'));
        $this->assertSame([], $this->names('tag=unknown'));
    }

    public function test_favorite_filter(): void
    {
        $this->assertEqualsCanonicalizing(['queues', 'zebra'], $this->names('is_favorite=true'));
        $this->assertEqualsCanonicalizing(['queues', 'zebra'], $this->names('is_favorite=1'));
        $this->assertEqualsCanonicalizing(['pasta', 'arabic', 'alpha'], $this->names('is_favorite=false'));
    }

    public function test_archived_filter_matches_nothing_for_true_and_everything_for_false(): void
    {
        $this->assertSame([], $this->names('is_archived=true'));
        $this->assertCount(5, $this->names('is_archived=false'));
        $this->assertCount(5, $this->names('is_archived=0'));
    }

    public function test_ai_summary_filter(): void
    {
        $this->assertSame(['queues'], $this->names('has_ai_summary=true'));
        $this->assertCount(4, $this->names('has_ai_summary=false'));
        $this->assertNotContains('queues', $this->names('has_ai_summary=0'));
    }

    public function test_date_filters_are_inclusive_and_a_plain_date_means_the_whole_day(): void
    {
        $this->assertEqualsCanonicalizing(['zebra', 'alpha'], $this->names('date_from=2026-03-20'));
        $this->assertEqualsCanonicalizing(['queues', 'pasta', 'arabic', 'zebra'], $this->names('date_to=2026-03-20'), 'zebra was saved at 23:59:59 on the 20th');
        $this->assertSame(['arabic', 'zebra'], array_reverse($this->names('date_from=2026-03-01&date_to=2026-03-20')));
        $this->assertSame(['zebra'], $this->names('date_from=2026-03-20&date_to=2026-03-20'));
        $this->assertSame(['alpha'], $this->names('date_from='.urlencode('2026-04-05T10:30:00Z')));
        $this->assertSame([], $this->names('date_from='.urlencode('2026-04-05T10:30:01Z')));
        $this->assertSame(['queues'], $this->names('date_to='.urlencode('2026-01-10T12:00:00Z')));
        $this->assertSame(['queues'], $this->names('date_to='.urlencode('2026-01-10T14:00:00+02:00')), 'Offsets are honoured.');
    }

    // ------------------------------------------------------------------ combinations

    public function test_filters_combine_with_and(): void
    {
        $dev = $this->user->collections()->where('name', 'Dev')->value('id');

        $this->assertSame(['queues'], $this->names("collection_id=$dev&is_favorite=true"));
        $this->assertSame(['alpha'], $this->names("collection_id=$dev&is_favorite=false"));
        $this->assertSame(['queues'], $this->names('query=laravel&tag=php&is_favorite=true&has_ai_summary=true&date_from=2026-01-01&date_to=2026-01-31'));
        $this->assertSame([], $this->names('query=laravel&tag=recipe'));
        $this->assertSame(['arabic'], $this->names('tag=php&is_favorite=false'));
        $this->assertSame(['zebra', 'queues'], $this->names('is_favorite=true&is_archived=false'));
    }

    // ------------------------------------------------------------------ sorting and pagination

    public function test_sorting_by_date_and_by_title_in_both_directions(): void
    {
        $this->assertSame(['alpha', 'zebra', 'arabic', 'pasta', 'queues'], $this->names('sort=created_at'));
        $this->assertSame(['queues', 'pasta', 'arabic', 'zebra', 'alpha'], $this->names('sort=created_at&direction=asc'));
        $this->assertSame(['alpha', 'queues', 'pasta', 'zebra', 'arabic'], $this->names('sort=title'), 'alpha notes, laravel queues, perfect pasta, zebra facts, then Arabic');
        $this->assertSame(['arabic', 'zebra', 'pasta', 'queues', 'alpha'], $this->names('sort=title&direction=desc'));
    }

    public function test_the_sort_title_follows_the_displayed_title(): void
    {
        $bare = $this->saveLink($this->user, 'https://bare.example/x');
        $bare->forceFill(['metadata_title' => 'Beta from the page'])->save();

        $this->resetAuth();
        $titles = array_column($this->search('sort=title')->assertOk()->json('data'), 'display_title');

        $this->assertSame('alpha notes', $titles[0]);
        $this->assertSame('Beta from the page', $titles[1]);
    }

    public function test_pages_never_skip_or_repeat_for_either_sort(): void
    {
        foreach (['sort=created_at&direction=asc', 'sort=created_at', 'sort=title', 'sort=title&direction=desc'] as $sort) {
            $seen = [];
            $cursor = null;
            for ($page = 0; $page < 5; $page++) {
                $this->resetAuth();
                $response = $this->search($sort.'&limit=2'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk();
                $seen = array_merge($seen, array_column($response->json('data'), 'id'));
                $cursor = $response->json('meta.next_cursor');
                if ($cursor === null) {
                    break;
                }
            }
            $this->assertCount(5, $seen, $sort);
            $this->assertCount(5, array_unique($seen), $sort);
            $this->resetAuth();
            $this->assertSame(array_column($this->search($sort)->json('data'), 'id'), $seen, $sort);
        }
    }

    public function test_total_is_on_the_first_page_only_and_counts_the_filtered_set(): void
    {
        $first = $this->search('is_favorite=true&limit=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.has_more', true);
        $this->resetAuth();
        $this->search('is_favorite=true&limit=1&cursor='.urlencode($first->json('meta.next_cursor')))->assertOk()->assertJsonMissingPath('meta.total')->assertJsonPath('meta.has_more', false);
    }

    public function test_a_cursor_only_works_with_the_filters_and_sort_it_came_from(): void
    {
        $cursor = $this->search('sort=title&limit=2')->json('meta.next_cursor');

        foreach (['sort=created_at', 'sort=title&direction=desc', 'sort=title&is_favorite=true', 'sort=title&query=a'] as $other) {
            $this->resetAuth();
            $this->assertApiError($this->search($other.'&cursor='.urlencode($cursor)), 422, 'INVALID_CURSOR');
        }
    }

    // ------------------------------------------------------------------ validation, isolation, auth

    public function test_invalid_parameters_are_rejected(): void
    {
        config(['stashly.rate_limits.api_per_minute' => 1000]);
        $bad = [
            'sort=last_viewed_at', 'sort=updated_at', 'sort=', 'direction=up', 'is_favorite=yes', 'is_archived=maybe', 'has_ai_summary=2',
            'date_from=yesterday', 'date_from=2026-13-45', 'date_to=10/01/2026', 'date_from=2026-05-01&date_to=2026-04-01',
            'limit=0', 'limit=51', 'limit=abc', 'query='.str_repeat('x', 201), 'tag='.str_repeat('t', 101), 'query[]=a',
        ];

        foreach ($bad as $query) {
            $this->resetAuth();
            $response = $this->search($query);
            if ($query === 'sort=') {
                $response->assertOk();   // an empty value means "not given"

                continue;
            }
            $this->assertApiError($response, 422, 'VALIDATION_FAILED');
        }
        $this->resetAuth();
        $this->assertApiError($this->search('cursor=garbage'), 422, 'INVALID_CURSOR');
    }

    public function test_it_only_ever_searches_the_callers_links(): void
    {
        $stranger = User::factory()->create();
        $this->saveLink($stranger, 'https://stranger.example/laravel', ['title' => 'Stranger laravel', 'is_favorite' => true]);

        $this->assertSame(['queues'], $this->names('query=laravel'));
        $this->resetAuth();
        $other = $this->search('query=laravel', $this->tokenFor($stranger))->assertOk();
        $this->assertCount(1, $other->json('data'));
        $this->assertSame('Stranger laravel', $other->json('data.0.title'));
    }

    public function test_it_requires_a_token_and_is_not_confused_with_a_link_id(): void
    {
        $this->flushHeaders();
        $this->resetAuth();
        $this->assertApiError($this->getJson('/api/v1/links/search?query=a'), 401, 'UNAUTHENTICATED');
    }

    public function test_the_plain_list_keeps_working_unchanged(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/links?q=queues&favorite=true')->assertOk()->assertJsonCount(1, 'data');
    }
}
