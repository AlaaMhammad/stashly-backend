<?php

namespace Tests\Feature\Api\V1;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API-PROF-004 GET /me/export (DEC-013 schema version 1).
 */
class ExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['display_name' => 'Lina', 'email' => 'lina@example.test']);
        Sanctum::actingAs($this->user);
    }

    private function download()
    {
        return $this->get('/api/v1/me/export');
    }

    private function document($response): array
    {
        $json = $response->streamedContent();
        $this->assertJson($json);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_is_a_json_attachment_that_is_never_cached(): void
    {
        $response = $this->download()->assertOk();

        $this->assertStringStartsWith('attachment; filename=stashly-export-', $response->headers->get('Content-Disposition'));
        $this->assertStringEndsWith('.json', trim($response->headers->get('Content-Disposition'), '"'));
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_contains_all_of_the_users_data_in_the_documented_schema(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $box = $this->user->collections()->create(['name' => 'Box', 'description' => 'Things']);
        $empty = $this->user->collections()->create(['name' => 'Empty']);
        $a = $this->saveLink($this->user, 'https://example.com/a', ['title' => 'Arabic عربي', 'note' => 'n', 'is_favorite' => true, 'collection_ids' => [$box->id], 'tags' => ['news', 'tech']]);
        $b = $this->saveLink($this->user, 'https://example.com/b?x=1', ['tags' => ['news']]);

        $doc = $this->document($this->download());

        $this->assertSame(['schema_version', 'exported_at', 'user', 'collections', 'tags', 'links'], array_keys($doc));
        $this->assertSame(1, $doc['schema_version']);
        $this->assertSame('2026-10-20T12:00:00Z', $doc['exported_at']);
        $this->assertSame([
            'id' => $this->user->id, 'display_name' => 'Lina', 'email' => 'lina@example.test',
            'created_at' => $doc['user']['created_at'], 'terms_version' => '1', 'terms_accepted_at' => $doc['user']['terms_accepted_at'],
        ], $doc['user']);
        $this->assertSame(['news', 'tech'], $doc['tags']);

        $this->assertEqualsCanonicalizing([$box->id, $empty->id], array_column($doc['collections'], 'id'));
        $exportedBox = collect($doc['collections'])->firstWhere('id', $box->id);
        $this->assertSame(['id', 'name', 'description', 'created_at', 'updated_at', 'link_ids'], array_keys($exportedBox));
        $this->assertSame([$a->id], $exportedBox['link_ids']);
        $this->assertSame([], collect($doc['collections'])->firstWhere('id', $empty->id)['link_ids']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($doc['links'], 'id'));
        $link = collect($doc['links'])->firstWhere('id', $a->id);
        $this->assertSame('Arabic عربي', $link['title']);
        $this->assertSame([['id' => $box->id, 'name' => 'Box']], $link['collections']);
        $this->assertSame(['news', 'tech'], $link['tags']);
        $this->assertSame('pending', $link['metadata']['status']);
        $this->assertSame(
            ['id', 'original_url', 'normalized_url', 'source_domain', 'title', 'display_title', 'note', 'is_favorite', 'content_type', 'metadata', 'ai', 'health', 'collections', 'collections_count', 'tags', 'created_at', 'updated_at'],
            array_keys($link),
        );
    }

    public function test_an_empty_account_exports_valid_empty_lists(): void
    {
        $doc = $this->document($this->download());

        $this->assertSame([], $doc['collections']);
        $this->assertSame([], $doc['tags']);
        $this->assertSame([], $doc['links']);
    }

    public function test_never_contains_another_users_data_or_any_secret(): void
    {
        $stranger = User::factory()->create(['email' => 'stranger@example.test']);
        $box = $stranger->collections()->create(['name' => 'Stranger box']);
        $this->saveLink($stranger, 'https://stranger.example/secret', ['title' => 'Stranger title', 'note' => 'stranger note', 'tags' => ['strangertag'], 'collection_ids' => [$box->id]]);
        $this->saveLink($this->user, 'https://example.com/mine', ['tags' => ['mine']]);
        $resetToken = $this->resetCodeFor($this->user);
        $token = $this->tokenFor($this->user);
        $this->withHeader('Idempotency-Key', '5f0c6d1e-7b2a-4c3d-9e8f-0a1b2c3d4e5f')->postJson('/api/v1/links', ['url' => 'https://example.com/keyed']);

        $raw = $this->download()->streamedContent();

        foreach (['stranger', 'Stranger', 'strangertag'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, $needle);
        }
        foreach ([$this->user->password, $resetToken, $token, explode('|', $token)[1], 'remember_token', 'password', 'idempotency', 'search_text', 'normalized_url_hash', 'user_id', 'metadata_request_id'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, 'leaked: '.substr($needle, 0, 12));
        }
        $this->assertCount(2, json_decode($raw, true)['links']);
    }

    public function test_exports_large_libraries_completely_across_chunks(): void
    {
        $rows = [];
        for ($i = 0; $i < 1203; $i++) {
            $id = strtolower((string) Str::ulid());
            $rows[] = [
                'id' => $id, 'user_id' => $this->user->id, 'original_url' => "https://example.com/$i",
                'normalized_url' => "https://example.com/$i", 'normalized_url_hash' => hash('sha256', "https://example.com/$i"),
                'source_domain' => 'example.com', 'content_type' => 'other', 'metadata_status' => 'ready',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('links')->insert($chunk);
        }

        $doc = $this->document($this->download());

        $this->assertCount(1203, $doc['links']);
        $this->assertCount(1203, array_unique(array_column($doc['links'], 'id')), 'No link repeated or skipped across chunks.');
        $this->assertSame(1203, Link::count());
    }

    public function test_is_limited_to_one_export_per_ten_minutes(): void
    {
        $this->download()->assertOk()->streamedContent();

        $this->assertApiError($this->download(), 429, 'RATE_LIMITED')->assertHeader('Retry-After');

        $this->travel(11)->minutes();
        $this->download()->assertOk();
    }

    public function test_requires_authentication_and_logs_no_content(): void
    {
        $this->saveLink($this->user, 'https://example.com/private-path?secret=1', ['note' => 'private note']);
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });

        $this->download()->streamedContent();
        $all = implode("\n", $logged);

        $this->assertStringContainsString('Account data exported', $all);
        $this->assertStringContainsString($this->user->id, $all);
        $this->assertStringNotContainsString('private', $all);
        $this->assertStringNotContainsString('lina@example.test', $all);

        $this->app['auth']->forgetGuards();
        $this->travel(11)->minutes();
        $this->assertApiError($this->download(), 401, 'UNAUTHENTICATED');
    }
}
