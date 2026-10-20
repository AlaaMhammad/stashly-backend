<?php

namespace Tests\Feature\Api\V1\Collections;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API-COLL-006 PATCH /collections/{collection}/links. SEC-004.
 */
class CollectionLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create();
        $this->collection = $this->user->collections()->create(['name' => 'Box']);
        Sanctum::actingAs($this->user);
    }

    private function patchMembers(array $body)
    {
        return $this->patchJson("/api/v1/collections/{$this->collection->id}/links", $body);
    }

    private function memberIds(): array
    {
        return $this->collection->links()->pluck('links.id')->sort()->values()->all();
    }

    public function test_attach_and_detach_in_one_call(): void
    {
        $a = $this->saveLink($this->user, 'https://example.com/a');
        $b = $this->saveLink($this->user, 'https://example.com/b');
        $c = $this->saveLink($this->user, 'https://example.com/c', ['collection_ids' => [$this->collection->id]]);

        $this->patchMembers(['attach' => [$a->id, strtoupper($b->id)], 'detach' => [$c->id]])
            ->assertOk()
            ->assertJsonPath('data.collection.id', $this->collection->id)
            ->assertJsonPath('data.collection.links_count', 2);

        $this->assertSame(collect([$a->id, $b->id])->sort()->values()->all(), $this->memberIds());
    }

    public function test_repeated_attach_and_detach_of_non_members_are_no_ops(): void
    {
        $a = $this->saveLink($this->user, 'https://example.com/a', ['collection_ids' => [$this->collection->id]]);
        $b = $this->saveLink($this->user, 'https://example.com/b');

        $this->patchMembers(['attach' => [$a->id, $a->id]])->assertOk()->assertJsonPath('data.collection.links_count', 1);
        $this->patchMembers(['detach' => [$b->id]])->assertOk()->assertJsonPath('data.collection.links_count', 1);
    }

    public function test_another_users_link_fails_the_whole_request_like_an_unknown_id(): void
    {
        $mine = $this->saveLink($this->user, 'https://example.com/mine');
        $theirs = $this->saveLink(User::factory()->create(), 'https://example.com/theirs');

        $foreign = $this->patchMembers(['attach' => [$mine->id, $theirs->id]]);
        $unknown = $this->patchMembers(['attach' => [$mine->id, '01jaaaaaaaaaaaaaaaaaaaaaaa']]);

        $this->assertApiError($foreign, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['attach.1']]]);
        $this->assertSame($foreign->json('error.details'), $unknown->json('error.details'));
        // Atomic: the valid id was not attached either.
        $this->assertSame([], $this->memberIds());
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_bodies_are_rejected(callable $body): void
    {
        $this->assertApiError($this->patchMembers($body($this)), 422, 'VALIDATION_FAILED');
    }

    public static function invalidBodies(): array
    {
        return [
            'nothing' => [fn () => []],
            'both empty' => [fn () => ['attach' => [], 'detach' => []]],
            'over 100 attach' => [fn () => ['attach' => array_fill(0, 101, '01jaaaaaaaaaaaaaaaaaaaaaaa')]],
            'over 100 detach' => [fn () => ['detach' => array_fill(0, 101, '01jaaaaaaaaaaaaaaaaaaaaaaa')]],
            'same id in both' => [fn ($test) => ['attach' => [$id = $test->linkId()], 'detach' => [$id]]],
        ];
    }

    public function linkId(): string
    {
        return $this->saveLink($this->user, 'https://example.com/x'.uniqid())->id;
    }

    public function test_another_users_collection_is_not_found(): void
    {
        $theirs = User::factory()->create()->collections()->create(['name' => 'Theirs']);
        $mine = $this->saveLink($this->user, 'https://example.com/mine');

        $this->assertApiError(
            $this->patchJson("/api/v1/collections/{$theirs->id}/links", ['attach' => [$mine->id]]),
            404,
            'NOT_FOUND',
        );
        $this->assertSame(0, $theirs->links()->count());
    }

    public function test_changing_membership_marks_the_collection_recently_updated(): void
    {
        $this->travelTo('2026-10-01 00:00:00');
        $collection = $this->user->collections()->create(['name' => 'Old']);
        $link = $this->saveLink($this->user, 'https://example.com/a');

        $this->travelTo('2026-10-05 00:00:00');
        $this->patchJson("/api/v1/collections/{$collection->id}/links", ['attach' => [$link->id]])
            ->assertJsonPath('data.collection.updated_at', '2026-10-05T00:00:00Z');
    }
}
