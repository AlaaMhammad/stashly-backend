<?php

namespace Tests\Feature\Api\V1\Links;

use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API-LINK-007 PUT /links/{link}/collections, API-LINK-008 PUT /links/{link}/tags,
 * and collections/tags on POST /links and DELETE /links/{link}.
 */
class LinkRelationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Link $link;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create();
        $this->link = $this->saveLink($this->user, 'https://example.com/a');
        Sanctum::actingAs($this->user);
    }

    private function tagNames(): array
    {
        return Tag::query()->whereBelongsTo($this->user)->orderBy('name')->pluck('name')->all();
    }

    public function test_replace_collections_and_empty_means_unsorted(): void
    {
        $zeta = $this->user->collections()->create(['name' => 'Zeta']);
        $alpha = $this->user->collections()->create(['name' => 'alpha']);

        $this->putJson("/api/v1/links/{$this->link->id}/collections", ['collection_ids' => [$zeta->id, strtoupper($alpha->id)]])
            ->assertOk()
            ->assertJsonPath('data.link.collections', [['id' => $alpha->id, 'name' => 'alpha'], ['id' => $zeta->id, 'name' => 'Zeta']])
            ->assertJsonPath('data.link.collections_count', 2);

        $this->putJson("/api/v1/links/{$this->link->id}/collections", ['collection_ids' => [$zeta->id]])
            ->assertOk()
            ->assertJsonPath('data.link.collections_count', 1);

        $this->putJson("/api/v1/links/{$this->link->id}/collections", ['collection_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.link.collections', [])
            ->assertJsonPath('data.link.collections_count', 0);

        $this->assertModelExists($zeta);
    }

    public function test_replace_collections_rejects_another_users_collection_without_changing_anything(): void
    {
        $mine = $this->user->collections()->create(['name' => 'Mine']);
        $this->link->collections()->attach($mine);
        $theirs = User::factory()->create()->collections()->create(['name' => 'Theirs']);

        $response = $this->putJson("/api/v1/links/{$this->link->id}/collections", ['collection_ids' => [$theirs->id]]);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['collection_ids.0']]]);
        $this->assertSame([$mine->id], $this->link->collections()->pluck('collections.id')->all());
        $this->assertSame(0, $theirs->links()->count());
    }

    public function test_replace_collections_requires_the_array_and_an_owned_link(): void
    {
        $this->assertApiError($this->putJson("/api/v1/links/{$this->link->id}/collections", []), 422, 'VALIDATION_FAILED');

        $theirs = $this->saveLink(User::factory()->create(), 'https://example.com/theirs');
        $this->assertApiError($this->putJson("/api/v1/links/{$theirs->id}/collections", ['collection_ids' => []]), 404, 'NOT_FOUND');
    }

    public function test_replace_tags_normalizes_and_deduplicates(): void
    {
        $this->putJson("/api/v1/links/{$this->link->id}/tags", ['tags' => ['  #News ', 'news', 'Big   Data', '##Tech']])
            ->assertOk()
            ->assertJsonPath('data.link.tags', ['big data', 'news', 'tech']);

        $this->assertSame(['big data', 'news', 'tech'], $this->tagNames());
    }

    public function test_replacing_tags_removes_tags_no_link_uses_any_more(): void
    {
        $other = $this->saveLink($this->user, 'https://example.com/b', ['tags' => ['shared']]);
        $this->putJson("/api/v1/links/{$this->link->id}/tags", ['tags' => ['shared', 'solo']])->assertOk();

        $this->putJson("/api/v1/links/{$this->link->id}/tags", ['tags' => ['fresh']])->assertOk()
            ->assertJsonPath('data.link.tags', ['fresh']);
        $this->assertSame(['fresh', 'shared'], $this->tagNames());

        $this->putJson("/api/v1/links/{$this->link->id}/tags", ['tags' => []])->assertOk()
            ->assertJsonPath('data.link.tags', []);
        $this->assertSame(['shared'], $this->tagNames());
        $this->assertSame(['shared'], $other->tags()->pluck('name')->all());
    }

    public function test_tags_are_per_user(): void
    {
        $stranger = User::factory()->create();
        $theirs = $this->saveLink($stranger, 'https://example.com/theirs', ['tags' => ['news']]);

        $this->putJson("/api/v1/links/{$this->link->id}/tags", ['tags' => ['news']])->assertOk();
        $this->putJson("/api/v1/links/{$this->link->id}/tags", ['tags' => []])->assertOk();

        $this->assertSame(['news'], $theirs->tags()->pluck('name')->all());
        $this->assertSame(1, Tag::count());
    }

    public function test_at_most_20_distinct_tags_per_link(): void
    {
        $twenty = array_map(fn ($i) => "t$i", range(1, 20));

        $this->putJson("/api/v1/links/{$this->link->id}/tags", ['tags' => $twenty])->assertOk();
        // Duplicates collapse before counting.
        $this->putJson("/api/v1/links/{$this->link->id}/tags", ['tags' => [...$twenty, 'T1', '#t2']])->assertOk();

        $response = $this->putJson("/api/v1/links/{$this->link->id}/tags", ['tags' => [...$twenty, 't21']]);
        $this->assertApiError($response, 422, 'LIMIT_EXCEEDED');
        $this->assertCount(20, $this->link->tags()->get());
    }

    public function test_invalid_tags_are_rejected(): void
    {
        foreach ([[str_repeat('t', 41)], ['a,b'], ['  #  '], [['nested']]] as $tags) {
            $this->assertApiError($this->putJson("/api/v1/links/{$this->link->id}/tags", ['tags' => $tags]), 422, 'VALIDATION_FAILED')
                ->assertJsonStructure(['error' => ['details' => ['tags.0']]]);
        }
        $this->putJson("/api/v1/links/{$this->link->id}/tags", ['tags' => [str_repeat('t', 40)]])->assertOk();
        $this->assertApiError($this->putJson("/api/v1/links/{$this->link->id}/tags", []), 422, 'VALIDATION_FAILED');
    }

    public function test_save_with_collections_and_tags(): void
    {
        $box = $this->user->collections()->create(['name' => 'Box']);

        $this->postJson('/api/v1/links', ['url' => 'example.com/new', 'collection_ids' => [$box->id], 'tags' => ['#Read', 'later']])
            ->assertCreated()
            ->assertJsonPath('data.link.collections', [['id' => $box->id, 'name' => 'Box']])
            ->assertJsonPath('data.link.collections_count', 1)
            ->assertJsonPath('data.link.tags', ['later', 'read']);
    }

    public function test_save_with_a_foreign_collection_or_too_many_tags_saves_nothing(): void
    {
        $theirs = User::factory()->create()->collections()->create(['name' => 'Theirs']);
        $before = Link::count();

        $this->assertApiError(
            $this->postJson('/api/v1/links', ['url' => 'example.com/x', 'collection_ids' => [$theirs->id]]),
            422,
            'VALIDATION_FAILED',
        )->assertJsonStructure(['error' => ['details' => ['collection_ids.0']]]);

        $this->assertApiError(
            $this->postJson('/api/v1/links', ['url' => 'example.com/y', 'tags' => array_map(fn ($i) => "t$i", range(1, 21))]),
            422,
            'LIMIT_EXCEEDED',
        );

        $this->assertSame($before, Link::count());
        $this->assertSame(0, Tag::count());
    }

    public function test_deleting_a_link_prunes_its_unused_tags_and_keeps_collections(): void
    {
        $box = $this->user->collections()->create(['name' => 'Box']);
        $doomed = $this->saveLink($this->user, 'https://example.com/doomed', ['tags' => ['solo', 'shared'], 'collection_ids' => [$box->id]]);
        $this->saveLink($this->user, 'https://example.com/keep', ['tags' => ['shared']]);

        $this->deleteJson("/api/v1/links/{$doomed->id}")->assertNoContent();

        $this->assertSame(['shared'], $this->tagNames());
        $this->assertModelExists($box);
        $this->assertSame(0, DB::table('collection_link')->where('link_id', $doomed->id)->count());
        $this->assertSame(0, DB::table('link_tag')->where('link_id', $doomed->id)->count());
    }

    public function test_link_list_loads_relations_with_a_fixed_number_of_queries(): void
    {
        $box = $this->user->collections()->create(['name' => 'Box']);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/links')->assertOk();

            return count(DB::getQueryLog());
        };

        $few = $count();
        foreach (range(1, 8) as $i) {
            $this->saveLink($this->user, "https://example.com/n$i", ['tags' => ["t$i"], 'collection_ids' => [$box->id]]);
        }

        $this->assertSame($few, $count());
    }
}
