<?php

namespace Tests\Feature\Api\V1\Collections;

use App\Models\Collection;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API-COLL-001..005: list, create, show, update, delete.
 */
class CollectionTest extends TestCase
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

    private function collection(string $name, ?User $owner = null): Collection
    {
        return ($owner ?? $this->user)->collections()->create(['name' => $name]);
    }

    private function ids($response): array
    {
        return array_column($response->json('data'), 'id');
    }

    public function test_create_returns_the_collection_object(): void
    {
        $this->travelTo('2026-10-16 09:30:12');

        $response = $this->postJson('/api/v1/collections', ['name' => '  Reading list  ', 'description' => 'Later'])
            ->assertCreated()
            ->assertExactJson(['data' => ['collection' => [
                'id' => Collection::sole()->id,
                'name' => 'Reading list',
                'description' => 'Later',
                'links_count' => 0,
                'cover_images' => [],
                'created_at' => '2026-10-16T09:30:12Z',
                'updated_at' => '2026-10-16T09:30:12Z',
            ]]]);

        $this->assertTrue(Str::isUlid($response->json('data.collection.id')));
        $this->assertTrue(Collection::sole()->user->is($this->user));
    }

    #[DataProvider('invalidCollections')]
    public function test_invalid_input_is_rejected(array $body, string $field): void
    {
        $this->assertApiError($this->postJson('/api/v1/collections', $body), 422, 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => [$field]]]);
        $this->assertSame(0, Collection::count());
    }

    public static function invalidCollections(): array
    {
        return [
            'missing name' => [[], 'name'],
            'blank name' => [['name' => '   '], 'name'],
            'name 61 chars' => [['name' => str_repeat('n', 61)], 'name'],
            'description 301 chars' => [['name' => 'ok', 'description' => str_repeat('d', 301)], 'description'],
        ];
    }

    public function test_length_boundaries_are_accepted(): void
    {
        $this->postJson('/api/v1/collections', ['name' => str_repeat('n', 60), 'description' => str_repeat('d', 300)])
            ->assertCreated();
        $this->postJson('/api/v1/collections', ['name' => 'x'])->assertCreated();
    }

    public function test_names_are_unique_per_user_ignoring_case(): void
    {
        $this->collection('News');

        $response = $this->postJson('/api/v1/collections', ['name' => ' nEWS ']);
        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['name']]]);

        // Accents are not case: both may exist.
        $this->postJson('/api/v1/collections', ['name' => 'Café'])->assertCreated();
        $this->postJson('/api/v1/collections', ['name' => 'Cafe'])->assertCreated();

        // Another user's names do not count.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/collections', ['name' => 'News'])->assertCreated();
    }

    public function test_name_taken_by_a_concurrent_request_is_reported_as_duplicate(): void
    {
        // The insert of a concurrent request lands after this request's
        // availability check, so only the unique index can catch it.
        $injected = false;
        Collection::creating(function (Collection $collection) use (&$injected) {
            if (! $injected) {
                $injected = true;
                DB::table('collections')->insert([
                    'id' => strtolower((string) Str::ulid()), 'user_id' => $collection->user_id,
                    'name' => 'race', 'name_key' => 'race', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $response = $this->postJson('/api/v1/collections', ['name' => 'Race']);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['name']]]);
    }

    public function test_a_user_can_have_at_most_100_collections(): void
    {
        for ($i = 1; $i <= 99; $i++) {
            $this->collection("c$i");
        }
        $this->postJson('/api/v1/collections', ['name' => 'c100'])->assertCreated();

        $response = $this->postJson('/api/v1/collections', ['name' => 'c101']);

        $this->assertApiError($response, 422, 'LIMIT_EXCEEDED');
        $this->assertSame(100, $this->user->collections()->count());

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/collections', ['name' => 'c101'])->assertCreated();
    }

    public function test_list_defaults_to_newest_first_with_meta(): void
    {
        foreach (['b', 'A', 'c'] as $i => $name) {
            $this->travelTo('2026-10-0'.($i + 1).' 00:00:00');
            $ids[] = $this->collection($name)->id;
        }

        $this->getJson('/api/v1/collections')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('data.0.id', $ids[2])
            ->assertJsonPath('data.2.id', $ids[0]);
    }

    public function test_name_sort_ignores_case_and_pages_with_a_cursor(): void
    {
        foreach (['banana', 'Apple', 'cherry', 'apricot'] as $name) {
            $this->collection($name);
        }

        $first = $this->getJson('/api/v1/collections?sort=name&limit=2')->assertOk()->assertJsonPath('meta.total', 4);
        $second = $this->getJson('/api/v1/collections?sort=name&limit=2&cursor='.$first->json('meta.next_cursor'))->assertOk();

        $this->assertSame(
            ['Apple', 'apricot', 'banana', 'cherry'],
            array_column(array_merge($first->json('data'), $second->json('data')), 'name'),
        );
        $this->assertArrayNotHasKey('total', $second->json('meta'));

        // A cursor is bound to its sort order.
        $this->assertApiError(
            $this->getJson('/api/v1/collections?sort=recent&limit=2&cursor='.$first->json('meta.next_cursor')),
            422,
            'INVALID_CURSOR',
        );
    }

    public function test_list_limit_bounds_and_isolation(): void
    {
        $mine = $this->collection('Mine');
        $this->collection('Theirs', User::factory()->create());

        $this->assertSame([$mine->id], $this->ids($this->getJson('/api/v1/collections?limit=100')));
        $this->assertApiError($this->getJson('/api/v1/collections?limit=101'), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->getJson('/api/v1/collections?sort=size'), 422, 'VALIDATION_FAILED');
    }

    public function test_links_count_and_up_to_four_newest_cover_images(): void
    {
        $collection = $this->collection('Pics');
        $empty = $this->collection('Empty');

        foreach (range(1, 6) as $i) {
            $this->travelTo("2026-10-01 00:00:0$i");
            $link = $this->saveLink($this->user, "https://example.com/$i", ['collection_ids' => [$collection->id]]);
            if ($i !== 5) {
                $link->forceFill(['metadata_preview_image_url' => "https://img.example/$i.jpg"])->save();
            }
        }

        $this->getJson("/api/v1/collections/{$collection->id}")
            ->assertOk()
            ->assertJsonPath('data.collection.links_count', 6)
            ->assertJsonPath('data.collection.cover_images', [
                'https://img.example/6.jpg', 'https://img.example/4.jpg', 'https://img.example/3.jpg', 'https://img.example/2.jpg',
            ]);

        $this->getJson("/api/v1/collections/{$empty->id}")
            ->assertOk()
            ->assertJsonPath('data.collection.links_count', 0)
            ->assertJsonPath('data.collection.cover_images', []);
    }

    public function test_list_uses_a_fixed_number_of_queries(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/collections')->assertOk();

            return count(DB::getQueryLog());
        };

        $this->collection('one');
        $few = $count();

        foreach (range(1, 8) as $i) {
            $c = $this->collection("c$i");
            $this->saveLink($this->user, "https://example.com/$i", ['collection_ids' => [$c->id]]);
        }

        $this->assertSame($few, $count());
    }

    public function test_show_update_and_delete_are_scoped_to_the_owner(): void
    {
        $theirs = $this->collection('Theirs', User::factory()->create());

        $this->assertApiError($this->getJson("/api/v1/collections/{$theirs->id}"), 404, 'NOT_FOUND');
        $this->assertApiError($this->patchJson("/api/v1/collections/{$theirs->id}", ['name' => 'Hijacked']), 404, 'NOT_FOUND');
        $this->assertApiError($this->deleteJson("/api/v1/collections/{$theirs->id}"), 404, 'NOT_FOUND');
        $this->assertApiError($this->getJson('/api/v1/collections/01JAAAAAAAAAAAAAAAAAAAAAAA'), 404, 'NOT_FOUND');

        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_update_renames_and_redescribes(): void
    {
        $collection = $this->collection('Old');
        $this->collection('Taken');

        $this->patchJson("/api/v1/collections/{$collection->id}", ['name' => 'New', 'description' => 'Desc'])
            ->assertOk()
            ->assertJsonPath('data.collection.name', 'New')
            ->assertJsonPath('data.collection.description', 'Desc');

        $this->patchJson("/api/v1/collections/{$collection->id}", ['description' => null])
            ->assertOk()
            ->assertJsonPath('data.collection.name', 'New')
            ->assertJsonPath('data.collection.description', null);

        // Changing only the case of its own name is allowed.
        $this->patchJson("/api/v1/collections/{$collection->id}", ['name' => 'NEW'])->assertOk();

        $this->assertApiError($this->patchJson("/api/v1/collections/{$collection->id}", ['name' => 'taken']), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->patchJson("/api/v1/collections/{$collection->id}", ['name' => null]), 422, 'VALIDATION_FAILED');
        $this->assertSame('NEW', $collection->fresh()->name);
    }

    public function test_delete_keeps_links_and_leaves_them_unsorted_or_in_other_collections(): void
    {
        $doomed = $this->collection('Doomed');
        $kept = $this->collection('Kept');
        $onlyHere = $this->saveLink($this->user, 'https://example.com/only', ['collection_ids' => [$doomed->id]]);
        $inBoth = $this->saveLink($this->user, 'https://example.com/both', ['collection_ids' => [$doomed->id, $kept->id]]);

        $this->deleteJson("/api/v1/collections/{$doomed->id}")->assertNoContent();

        $this->assertModelMissing($doomed);
        $this->assertSame(2, Link::count());
        $this->getJson("/api/v1/links/{$onlyHere->id}")->assertJsonPath('data.link.collections', [])->assertJsonPath('data.link.collections_count', 0);
        $this->getJson("/api/v1/links/{$inBoth->id}")->assertJsonPath('data.link.collections', [['id' => $kept->id, 'name' => 'Kept']]);
    }
}
