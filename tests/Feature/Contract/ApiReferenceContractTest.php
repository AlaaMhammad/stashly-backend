<?php

namespace Tests\Feature\Contract;

use App\Jobs\FetchLinkMetadata;
use App\Models\Link;
use App\Models\User;
use App\Services\Metadata\MetadataProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Support\FakeTransport;
use Tests\TestCase;

/**
 * One journey through all 32 operations of the Stashly API Reference v1.0,
 * asserting each documented status code and response shape (types, nullability,
 * envelopes, ULIDs, UTC timestamps). Complements the per-feature tests.
 */
class ApiReferenceContractTest extends TestCase
{
    use RefreshDatabase;

    private const LINK_KEYS = ['id', 'original_url', 'normalized_url', 'source_domain', 'title', 'display_title', 'note', 'is_favorite', 'content_type', 'metadata', 'ai', 'collections', 'collections_count', 'tags', 'created_at', 'updated_at'];

    private const METADATA_KEYS = ['status', 'title', 'description', 'site_name', 'favicon_url', 'preview_image_url', 'fetched_at', 'error_code'];

    private const COLLECTION_KEYS = ['id', 'name', 'description', 'links_count', 'cover_images', 'created_at', 'updated_at'];

    private function assertTimestamp(mixed $value): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $value);
    }

    private function assertLink(array $link): void
    {
        $this->assertSame(self::LINK_KEYS, array_keys($link));
        $this->assertTrue(Str::isUlid($link['id']));
        $this->assertSame(self::METADATA_KEYS, array_keys($link['metadata']));
        $this->assertContains($link['metadata']['status'], ['pending', 'ready', 'failed', 'blocked']);
        $this->assertContains($link['content_type'], ['video', 'article', 'product', 'social', 'other']);
        $this->assertIsBool($link['is_favorite']);
        $this->assertIsString($link['display_title']);
        $this->assertIsArray($link['collections']);
        $this->assertIsInt($link['collections_count']);
        $this->assertIsArray($link['tags']);
        $this->assertTimestamp($link['created_at']);
        $this->assertTimestamp($link['updated_at']);
    }

    private function assertCollection(array $collection): void
    {
        $this->assertSame(self::COLLECTION_KEYS, array_keys($collection));
        $this->assertTrue(Str::isUlid($collection['id']));
        $this->assertIsInt($collection['links_count']);
        $this->assertLessThanOrEqual(4, count($collection['cover_images']));
        $this->assertTimestamp($collection['created_at']);
    }

    private function assertToken(array $token): void
    {
        $this->assertSame(['access_token', 'token_type', 'expires_at'], array_keys($token));
        $this->assertSame('Bearer', $token['token_type']);
        $this->assertTimestamp($token['expires_at']);
    }

    private function assertListMeta(array $meta, bool $first): void
    {
        $expected = $first ? ['per_page', 'has_more', 'next_cursor', 'total'] : ['per_page', 'has_more', 'next_cursor'];
        $this->assertSame($expected, array_keys($meta));
    }

    public function test_all_32_operations_follow_the_api_reference(): void
    {
        Notification::fake();

        // --- System (2)
        $this->getJson('/api/v1/health')->assertOk()->assertJsonStructure(['data' => ['status', 'time', 'checks' => ['database', 'cache', 'queue']]]);
        $this->getJson('/api/v1/config')->assertOk()->assertJsonStructure(['data' => ['api_version', 'min_supported_app_version', 'latest_app_version', 'terms' => ['version', 'url'], 'privacy' => ['url'], 'limits']]);

        // --- Authentication (7) and profile
        $registered = $this->postJson('/api/v1/auth/register', [
            'display_name' => 'Lina', 'email' => 'lina@example.test', 'password' => 'first-password', 'password_confirmation' => 'first-password',
            'terms_accepted' => true, 'terms_version' => '1', 'device_name' => 'Pixel',
        ])->assertCreated()->json('data');
        $this->assertSame(['user', 'token'], array_keys($registered));
        $this->assertSame(['id', 'display_name', 'email', 'created_at', 'password_set'], array_keys($registered['user']));
        $this->assertToken($registered['token']);
        $this->postJson('/api/v1/auth/register', [
            'display_name' => 'Dup', 'email' => 'lina@example.test', 'password' => 'first-password', 'password_confirmation' => 'first-password', 'terms_accepted' => true,
        ])->assertStatus(422);

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'first-password'])->assertOk()->json('data');
        $this->assertToken($login['token']);
        $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'nope'])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $auth = ['Authorization' => 'Bearer '.$login['token']['access_token']];

        $this->getJson('/api/v1/me', $auth)->assertOk()->assertJsonStructure(['data' => ['user' => ['id', 'display_name', 'email', 'created_at', 'password_set']]]);
        $this->patchJson('/api/v1/me', ['display_name' => 'Lina M'], $auth)->assertOk()->assertJsonPath('data.user.display_name', 'Lina M');

        // --- Links (9)
        $this->transport->on('https://example.com/post', FakeTransport::html('<title>Fetched</title><meta property="og:image" content="https://cdn.example.com/i.jpg">'));
        $preview = $this->postJson('/api/v1/links/preview', ['url' => 'example.com/post?utm_source=x'], $auth)->assertOk()->json('data');
        $this->assertSame(['url', 'normalized_url', 'source_domain', 'content_type', 'duplicate', 'existing_link', 'metadata'], array_keys($preview));
        $this->assertFalse($preview['duplicate']);
        $this->postJson('/api/v1/links/preview', ['url' => 'javascript:alert(1)'], $auth)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_URL');

        $created = $this->withHeader('Idempotency-Key', '5f0c6d1e-7b2a-4c3d-9e8f-0a1b2c3d4e5f')
            ->postJson('/api/v1/links', ['url' => 'example.com/post?utm_source=x', 'note' => 'n'], $auth)->assertCreated()->json('data.link');
        $this->flushHeaders(); // withHeader persists; the key must not leak into later requests
        $this->assertLink($created);
        $this->assertSame('pending', $created['metadata']['status']);
        $linkId = $created['id'];

        $dup = $this->postJson('/api/v1/links', ['url' => 'https://www.example.com/post/'], $auth)->assertStatus(409);
        $dup->assertJsonPath('error.code', 'DUPLICATE_LINK')->assertJsonPath('error.details.existing_link.id', $linkId);

        $job = Link::find($linkId);
        (new FetchLinkMetadata($job->id, $job->metadata_request_id))->handle(app(MetadataProcessor::class));
        $detail = $this->getJson("/api/v1/links/$linkId", $auth)->assertOk()->json('data.link');
        $this->assertLink($detail);
        $this->assertSame('ready', $detail['metadata']['status']);
        $this->assertSame('Fetched', $detail['metadata']['title']);
        $this->getJson('/api/v1/links/01jaaaaaaaaaaaaaaaaaaaaaaa', $auth)->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        $list = $this->getJson('/api/v1/links?limit=1', $auth)->assertOk();
        $this->assertListMeta($list->json('meta'), true);
        $this->assertLink($list->json('data.0'));
        $this->getJson('/api/v1/links?cursor=garbage', $auth)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CURSOR');

        $this->patchJson("/api/v1/links/$linkId", ['title' => 'Mine', 'is_favorite' => true], $auth)->assertOk()->assertJsonPath('data.link.display_title', 'Mine');
        $this->patchJson("/api/v1/links/$linkId", ['normalized_url' => 'x'], $auth)->assertStatus(422);

        // --- Collections (6), link collections/tags
        $collection = $this->postJson('/api/v1/collections', ['name' => 'Reading', 'description' => 'd'], $auth)->assertCreated()->json('data.collection');
        $this->assertCollection($collection);
        $this->postJson('/api/v1/collections', ['name' => 'reading'], $auth)->assertStatus(422);
        $cid = $collection['id'];

        $this->putJson("/api/v1/links/$linkId/collections", ['collection_ids' => [$cid]], $auth)->assertOk()->assertJsonPath('data.link.collections_count', 1);
        $this->putJson("/api/v1/links/$linkId/tags", ['tags' => ['#News', 'tech']], $auth)->assertOk()->assertJsonPath('data.link.tags', ['news', 'tech']);

        $collections = $this->getJson('/api/v1/collections', $auth)->assertOk();
        $this->assertListMeta($collections->json('meta'), true);
        $this->assertCollection($collections->json('data.0'));
        $this->assertSame(1, $collections->json('data.0.links_count'));
        $this->assertSame(['https://cdn.example.com/i.jpg'], $collections->json('data.0.cover_images'));
        $this->getJson("/api/v1/collections/$cid", $auth)->assertOk();
        $this->patchJson("/api/v1/collections/$cid", ['name' => 'Read later'], $auth)->assertOk()->assertJsonPath('data.collection.name', 'Read later');
        $this->patchJson("/api/v1/collections/$cid/links", ['detach' => [$linkId]], $auth)->assertOk()->assertJsonPath('data.collection.links_count', 0);
        $this->patchJson("/api/v1/collections/$cid/links", ['attach' => [$linkId]], $auth)->assertOk()->assertJsonPath('data.collection.links_count', 1);

        // --- Tags (1), search (1), home (1), stats
        $this->getJson('/api/v1/tags', $auth)->assertOk()->assertExactJson(['data' => [['name' => 'news', 'links_count' => 1], ['name' => 'tech', 'links_count' => 1]]]);
        $this->getJson('/api/v1/search/filters', $auth)->assertOk()->assertJsonStructure(['data' => ['content_types', 'sources', 'collections', 'tags', 'date_presets']]);
        $home = $this->getJson('/api/v1/home', $auth)->assertOk()->json('data');
        $this->assertSame(['user', 'links_count', 'favorites_count', 'quick_access', 'recent_links'], array_keys($home));
        $this->assertSame(['display_name'], array_keys($home['user']));
        $this->assertLink($home['recent_links'][0]);
        $this->assertCollection($home['quick_access'][0]);
        $this->getJson('/api/v1/me/stats', $auth)->assertOk()->assertExactJson(['data' => ['links_count' => 1, 'collections_count' => 1, 'favorites_count' => 1]]);

        // --- Metadata refresh
        $this->postJson("/api/v1/links/$linkId/metadata/refresh", [], $auth)->assertStatus(202)->assertJsonPath('data.link.metadata.status', 'pending');

        // --- Export (1)
        $export = $this->getJson('/api/v1/me/export', $auth)->assertOk();
        $this->assertStringContainsString('attachment', $export->headers->get('Content-Disposition'));
        $this->assertSame(1, json_decode($export->streamedContent(), true)['schema_version']);

        // --- Password lifecycle: change, forgot, reset
        $this->putJson('/api/v1/me/password', ['current_password' => 'first-password', 'password' => 'second-password', 'password_confirmation' => 'second-password'], $auth)
            ->assertOk()->assertJsonStructure(['data' => ['message']]);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])->assertOk()->assertJsonStructure(['data' => ['message']]);
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'lina@example.test', 'token' => 'bad', 'password' => 'third-password', 'password_confirmation' => 'third-password'])
            ->assertStatus(422)->assertJsonPath('error.code', 'TOKEN_INVALID_OR_EXPIRED');
        $resetToken = Password::broker()->createToken(User::sole());
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'lina@example.test', 'token' => $resetToken, 'password' => 'third-password', 'password_confirmation' => 'third-password'])
            ->assertOk()->assertJsonStructure(['data' => ['message']]);

        // --- Tokens: login, refresh, logout, delete link / collection / account
        $this->app['auth']->forgetGuards();
        $fresh = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'third-password'])->json('data.token.access_token');
        $auth = ['Authorization' => 'Bearer '.$fresh];
        $refreshed = $this->postJson('/api/v1/auth/refresh', [], $auth)->assertOk()->json('data');
        $this->assertSame(['token'], array_keys($refreshed));
        $this->assertToken($refreshed['token']);
        $auth = ['Authorization' => 'Bearer '.$refreshed['token']['access_token']];

        $this->app['auth']->forgetGuards();
        $this->deleteJson("/api/v1/links/$linkId", [], $auth)->assertNoContent();
        $this->deleteJson("/api/v1/collections/$cid", [], $auth)->assertNoContent();
        $this->postJson('/api/v1/auth/logout', ['all_devices' => false], $auth)->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $auth)->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $last = $this->postJson('/api/v1/auth/login', ['email' => 'lina@example.test', 'password' => 'third-password'])->json('data.token.access_token');
        $this->app['auth']->forgetGuards();
        $this->deleteJson('/api/v1/me', ['password' => 'wrong'], ['Authorization' => 'Bearer '.$last])->assertStatus(422);
        $this->deleteJson('/api/v1/me', ['password' => 'third-password'], ['Authorization' => 'Bearer '.$last])->assertNoContent();
        $this->assertSame(0, User::count());
    }
}
