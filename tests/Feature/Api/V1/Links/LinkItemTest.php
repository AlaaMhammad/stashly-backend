<?php

namespace Tests\Feature\Api\V1\Links;

use App\Jobs\FetchLinkMetadata;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API-LINK-004 GET, API-LINK-005 PATCH, API-LINK-006 DELETE /links/{link},
 * API-LINK-009 POST /links/{link}/metadata/refresh. SEC-003, SEC-005.
 */
class LinkItemTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Link $link;

    private Link $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create();
        $this->link = $this->saveLink($this->user, 'https://example.com/mine', ['title' => 'Mine', 'note' => 'My note']);
        $this->theirs = $this->saveLink(User::factory()->create(), 'https://example.com/theirs', ['title' => 'Theirs']);
        Queue::fake(); // ignore the setup dispatches
        Sanctum::actingAs($this->user);
    }

    public function test_show_returns_the_link(): void
    {
        $this->getJson("/api/v1/links/{$this->link->id}")
            ->assertOk()
            ->assertJsonPath('data.link.id', $this->link->id)
            ->assertJsonPath('data.link.title', 'Mine')
            ->assertJsonPath('data.link.metadata.status', 'pending')
            ->assertJsonPath('data.link.collections', [])
            ->assertJsonPath('data.link.tags', []);
    }

    public function test_ids_are_case_insensitive(): void
    {
        $this->assertSame(strtolower($this->link->id), $this->link->id, 'HasUlids stores lowercase ids.');

        $this->getJson('/api/v1/links/'.strtoupper($this->link->id))
            ->assertOk()
            ->assertJsonPath('data.link.id', $this->link->id);
    }

    #[DataProvider('itemRequests')]
    public function test_another_users_link_is_not_found_and_untouched(string $method, string $suffix, array $body): void
    {
        $response = $this->json($method, "/api/v1/links/{$this->theirs->id}$suffix", $body);

        $this->assertApiError($response, 404, 'NOT_FOUND');
        $this->assertStringNotContainsString('Theirs', $response->getContent());

        $fresh = $this->theirs->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Theirs', $fresh->title);
        Queue::assertNothingPushed();
    }

    #[DataProvider('itemRequests')]
    public function test_unknown_and_malformed_ids_are_not_found(string $method, string $suffix, array $body): void
    {
        $this->assertApiError($this->json($method, "/api/v1/links/01JAAAAAAAAAAAAAAAAAAAAAAA$suffix", $body), 404, 'NOT_FOUND');
        $this->assertApiError($this->json($method, "/api/v1/links/not-a-ulid$suffix", $body), 404, 'NOT_FOUND');
    }

    public static function itemRequests(): array
    {
        return [
            'show' => ['GET', '', []],
            'update' => ['PATCH', '', ['title' => 'hijacked']],
            'delete' => ['DELETE', '', []],
            'refresh' => ['POST', '/metadata/refresh', []],
        ];
    }

    public function test_update_changes_only_the_sent_fields(): void
    {
        $this->patchJson("/api/v1/links/{$this->link->id}", ['is_favorite' => true])
            ->assertOk()
            ->assertJsonPath('data.link.is_favorite', true)
            ->assertJsonPath('data.link.title', 'Mine')
            ->assertJsonPath('data.link.note', 'My note');

        $this->patchJson("/api/v1/links/{$this->link->id}", ['title' => '  New title  ', 'note' => 'New note'])
            ->assertOk()
            ->assertJsonPath('data.link.title', 'New title')
            ->assertJsonPath('data.link.display_title', 'New title')
            ->assertJsonPath('data.link.note', 'New note')
            ->assertJsonPath('data.link.is_favorite', true);
    }

    #[DataProvider('clearingValues')]
    public function test_null_or_empty_clears_title_and_note(mixed $value): void
    {
        $this->patchJson("/api/v1/links/{$this->link->id}", ['title' => $value, 'note' => $value])
            ->assertOk()
            ->assertJsonPath('data.link.title', null)
            ->assertJsonPath('data.link.note', null)
            ->assertJsonPath('data.link.display_title', 'example.com');
    }

    public static function clearingValues(): array
    {
        return ['null' => [null], 'empty string' => [''], 'whitespace' => ['   ']];
    }

    #[DataProvider('readOnlyFields')]
    public function test_read_only_fields_are_rejected_and_nothing_changes(string $field, mixed $value): void
    {
        $response = $this->patchJson("/api/v1/links/{$this->link->id}", ['title' => 'Changed', $field => $value]);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => [$field]]]);
        $this->assertSame('Mine', $this->link->fresh()->title);
    }

    public static function readOnlyFields(): array
    {
        return [
            'id' => ['id', '01JAAAAAAAAAAAAAAAAAAAAAAA'],
            'user_id' => ['user_id', '01JAAAAAAAAAAAAAAAAAAAAAAA'],
            'original_url' => ['original_url', 'https://evil.example'],
            'normalized_url' => ['normalized_url', 'https://evil.example'],
            'source_domain' => ['source_domain', 'evil.example'],
            'display_title' => ['display_title', 'x'],
            'content_type' => ['content_type', 'video'],
            'metadata' => ['metadata', ['status' => 'ready']],
            'collections' => ['collections', []],
            'collections_count' => ['collections_count', 3],
            'tags' => ['tags', ['news']],
            'created_at' => ['created_at', '2020-01-01T00:00:00Z'],
            'updated_at' => ['updated_at', '2020-01-01T00:00:00Z'],
            'unknown field' => ['foo', 'bar'],
        ];
    }

    #[DataProvider('invalidEdits')]
    public function test_invalid_edits_are_rejected(array $body, string $field): void
    {
        $response = $this->patchJson("/api/v1/links/{$this->link->id}", $body);

        $this->assertApiError($response, 422, 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => [$field]]]);
    }

    public static function invalidEdits(): array
    {
        return [
            'title 301' => [['title' => str_repeat('t', 301)], 'title'],
            'note 2001' => [['note' => str_repeat('n', 2001)], 'note'],
            'favorite null' => [['is_favorite' => null], 'is_favorite'],
            'favorite string' => [['is_favorite' => 'maybe'], 'is_favorite'],
        ];
    }

    public function test_update_length_boundaries_are_accepted(): void
    {
        $this->patchJson("/api/v1/links/{$this->link->id}", ['title' => str_repeat('t', 300), 'note' => str_repeat('n', 2000)])
            ->assertOk();
    }

    public function test_delete_removes_the_link_permanently(): void
    {
        $this->deleteJson("/api/v1/links/{$this->link->id}")->assertNoContent();

        $this->assertModelMissing($this->link);
        $this->assertApiError($this->deleteJson("/api/v1/links/{$this->link->id}"), 404, 'NOT_FOUND');
        $this->assertModelExists($this->theirs);
    }

    public function test_refresh_sets_pending_queues_the_job_and_returns_202(): void
    {
        $this->link->forceFill([
            'metadata_status' => Link::STATUS_FAILED,
            'metadata_error_code' => 'TIMEOUT',
            'metadata_title' => 'Old fetched title',
        ])->save();

        $this->postJson("/api/v1/links/{$this->link->id}/metadata/refresh")
            ->assertStatus(202)
            ->assertJsonPath('data.link.id', $this->link->id)
            ->assertJsonPath('data.link.metadata.status', 'pending')
            ->assertJsonPath('data.link.metadata.error_code', null)
            ->assertJsonPath('data.link.metadata.title', 'Old fetched title');

        Queue::assertPushed(FetchLinkMetadata::class, fn (FetchLinkMetadata $job) => $job->linkId === $this->link->id);
    }

    public function test_refresh_is_limited_to_once_per_minute_per_link(): void
    {
        $other = $this->saveLink($this->user, 'https://example.com/second');

        $this->postJson("/api/v1/links/{$this->link->id}/metadata/refresh")->assertStatus(202);

        $response = $this->postJson("/api/v1/links/{$this->link->id}/metadata/refresh");
        $this->assertApiError($response, 429, 'RATE_LIMITED')->assertHeader('Retry-After');

        $this->postJson("/api/v1/links/{$other->id}/metadata/refresh")->assertStatus(202);

        $this->travel(61)->seconds();
        $this->postJson("/api/v1/links/{$this->link->id}/metadata/refresh")->assertStatus(202);
    }

    public function test_refresh_is_limited_to_five_per_minute_per_user(): void
    {
        $links = [];
        for ($i = 0; $i < 6; $i++) {
            $links[] = $this->saveLink($this->user, "https://example.com/r$i");
        }

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/v1/links/{$links[$i]->id}/metadata/refresh")->assertStatus(202);
        }

        $this->assertApiError($this->postJson("/api/v1/links/{$links[5]->id}/metadata/refresh"), 429, 'RATE_LIMITED');
    }

    public function test_item_routes_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->assertApiError($this->getJson("/api/v1/links/{$this->link->id}"), 401, 'UNAUTHENTICATED');
        $this->assertApiError($this->getJson('/api/v1/links'), 401, 'UNAUTHENTICATED');
    }
}
