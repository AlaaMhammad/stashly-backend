<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\User;
use App\Services\Metadata\FetchFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeTransport;
use Tests\TestCase;

/**
 * API-LINK-014 POST /links/{link}/check-health and API-LINK-013 GET /links/broken (DEC-041).
 *
 * The brief suggested Http::fake(); the check goes through the same SSRF guard and IP-pinned
 * transport as the metadata fetch (Laravel's HTTP client cannot pin the connection to a checked
 * address), so these tests fake that transport instead.
 */
class DeadLinkCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->tokenFor($this->user);
    }

    private function link(string $url = 'https://example.com/page', ?User $owner = null): Link
    {
        return $this->saveLink($owner ?? $this->user, $url, ['title' => 'Page']);
    }

    private function check(Link $link, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->postJson('/api/v1/links/'.$link->id.'/check-health');
    }

    // ------------------------------------------------------------------ outcomes

    public function test_a_live_link_is_not_broken_and_the_result_is_stored(): void
    {
        $link = $this->link();
        $this->transport->on('https://example.com/page', FakeTransport::status(200));
        $this->freezeSecond();

        $this->check($link)->assertOk()->assertJson(['data' => [
            'link_id' => $link->id,
            'status_code' => 200,
            'is_broken' => false,
            'error' => null,
            'last_checked_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]])->assertJsonStructure(['data' => ['response_time_ms']]);

        $fresh = $link->fresh();
        $this->assertFalse($fresh->is_broken);
        $this->assertNotNull($fresh->last_checked_at);
        $this->assertIsInt($this->check($link)->json('data.response_time_ms'));
    }

    public function test_the_request_is_a_head_with_a_five_second_budget_and_a_tiny_body_cap(): void
    {
        $link = $this->link();
        $this->transport->on('https://example.com/page', FakeTransport::status(200));
        $this->check($link)->assertOk();

        $this->assertCount(1, $this->transport->requests);
        $request = $this->transport->requests[0];
        $this->assertSame('HEAD', $request['method']);
        $this->assertLessThanOrEqual(5.0, $request['timeout']);
        $this->assertGreaterThan(4.0, $request['timeout']);
        $this->assertSame(1024, $request['maxBytes']);
    }

    public function test_error_statuses_mark_the_link_broken(): void
    {
        foreach ([404, 410, 500, 502, 503, 400] as $status) {
            $link = $this->link("https://example.com/s$status");
            $this->transport->on("https://example.com/s$status", FakeTransport::status($status));
            $this->resetAuth();

            $this->check($link)->assertOk()
                ->assertJsonPath('data.status_code', $status)
                ->assertJsonPath('data.is_broken', true)
                ->assertJsonPath('data.error', "http_$status");
            $this->assertTrue($link->fresh()->is_broken, (string) $status);
        }
    }

    public function test_restricted_but_answering_servers_are_alive(): void
    {
        foreach ([401, 403, 429] as $status) {
            $link = $this->link("https://example.com/r$status");
            $this->transport->on("https://example.com/r$status", FakeTransport::status($status));
            $this->resetAuth();

            $this->check($link)->assertOk()->assertJsonPath('data.status_code', $status)->assertJsonPath('data.is_broken', false);
        }
    }

    public function test_a_broken_link_that_works_again_is_cleared(): void
    {
        $link = $this->link();
        $this->transport->on('https://example.com/page', FakeTransport::status(404), FakeTransport::status(200));

        $this->check($link)->assertOk()->assertJsonPath('data.is_broken', true);
        $this->resetAuth();
        $this->check($link)->assertOk()->assertJsonPath('data.is_broken', false);

        $this->assertFalse($link->fresh()->is_broken);
    }

    public function test_head_refused_with_405_or_501_falls_back_to_get(): void
    {
        foreach ([405, 501] as $status) {
            $link = $this->link("https://example.com/h$status");
            $this->transport->on("https://example.com/h$status", FakeTransport::status($status), FakeTransport::status(200));
            $this->transport->requests = [];
            $this->resetAuth();

            $this->check($link)->assertOk()->assertJsonPath('data.status_code', 200)->assertJsonPath('data.is_broken', false);
            $this->assertSame(['HEAD', 'GET'], array_column($this->transport->requests, 'method'));
        }
    }

    public function test_a_server_that_refuses_both_head_and_get_with_405_is_still_alive(): void
    {
        $link = $this->link();
        $this->transport->on('https://example.com/page', FakeTransport::status(405));

        $this->check($link)->assertOk()->assertJsonPath('data.is_broken', false);
        $this->assertCount(2, $this->transport->requests);
    }

    // ------------------------------------------------------------------ redirects

    public function test_up_to_three_redirects_are_followed(): void
    {
        $link = $this->link('https://example.com/a');
        $this->transport
            ->on('https://example.com/a', FakeTransport::redirect('/b', 301))
            ->on('https://example.com/b', FakeTransport::redirect('https://other.example/c', 302))
            ->on('https://other.example/c', FakeTransport::redirect('/d', 307))
            ->on('https://other.example/d', FakeTransport::status(200));

        $this->check($link)->assertOk()->assertJsonPath('data.status_code', 200)->assertJsonPath('data.is_broken', false);
        $this->assertCount(4, $this->transport->requests);
    }

    public function test_more_than_three_redirects_is_broken(): void
    {
        $link = $this->link('https://example.com/a');
        $this->transport
            ->on('https://example.com/a', FakeTransport::redirect('/b'))
            ->on('https://example.com/b', FakeTransport::redirect('/c'))
            ->on('https://example.com/c', FakeTransport::redirect('/d'))
            ->on('https://example.com/d', FakeTransport::redirect('/e'))
            ->on('https://example.com/e', FakeTransport::status(200));

        $this->check($link)->assertOk()->assertJsonPath('data.is_broken', true)->assertJsonPath('data.error', 'too_many_redirects')->assertJsonPath('data.status_code', null);
    }

    public function test_a_redirect_into_the_private_network_is_blocked_not_followed_and_does_not_change_the_state(): void
    {
        $link = $this->link('https://example.com/a');
        $link->forceFill(['is_broken' => true])->saveQuietly();
        $this->transport->on('https://example.com/a', FakeTransport::redirect('http://internal.example/admin'));
        $this->dns->records['internal.example'] = ['10.0.0.5'];

        $this->check($link)->assertOk()->assertJsonPath('data.error', 'blocked')->assertJsonPath('data.is_broken', true);

        $this->assertCount(1, $this->transport->requests, 'The private address was never requested.');
        $this->assertTrue($link->fresh()->is_broken, 'Not being allowed to look says nothing about the link.');
        $this->assertNull($link->fresh()->last_checked_at);
    }

    public function test_a_link_to_a_private_address_is_never_requested(): void
    {
        $link = $this->link();
        $this->dns->records['example.com'] = ['127.0.0.1'];
        $this->transport->on('https://example.com/page', FakeTransport::status(200));

        $this->check($link)->assertOk()->assertJsonPath('data.error', 'blocked')->assertJsonPath('data.is_broken', false);
        $this->assertSame([], $this->transport->requests);
    }

    // ------------------------------------------------------------------ failures

    public function test_timeouts_and_connection_failures_are_broken(): void
    {
        $cases = [
            'timeout' => FetchFailure::timeout(),
            'tls_error' => FetchFailure::tls(),
            'unreachable' => FetchFailure::unreachable(),
        ];

        foreach ($cases as $error => $failure) {
            $link = $this->link("https://example.com/$error");
            $this->transport->on("https://example.com/$error", $failure);
            $this->resetAuth();

            $this->check($link)->assertOk()
                ->assertJsonPath('data.is_broken', true)
                ->assertJsonPath('data.error', $error)
                ->assertJsonPath('data.status_code', null);
            $this->assertTrue($link->fresh()->is_broken, $error);
            $this->assertNotNull($link->fresh()->last_checked_at);
        }
    }

    public function test_an_unconfigured_host_counts_as_unreachable(): void
    {
        $link = $this->link('https://nowhere.example/x');

        $this->check($link)->assertOk()->assertJsonPath('data.error', 'unreachable')->assertJsonPath('data.is_broken', true);
    }

    public function test_the_whole_check_shares_one_time_budget(): void
    {
        $link = $this->link('https://example.com/a');
        config(['stashly.health.timeout_seconds' => 0.3]);
        $this->transport->delay = 0.2;
        $this->transport
            ->on('https://example.com/a', FakeTransport::redirect('/b'))
            ->on('https://example.com/b', FakeTransport::redirect('/c'))
            ->on('https://example.com/c', FakeTransport::status(200));

        $this->check($link)->assertOk()->assertJsonPath('data.error', 'timeout')->assertJsonPath('data.is_broken', true);
    }

    // ------------------------------------------------------------------ side effects and authorization

    public function test_a_check_does_not_touch_updated_at_or_the_search_index(): void
    {
        $link = $this->link();
        $before = $link->fresh();
        $this->travel(2)->hours();
        $this->transport->on('https://example.com/page', FakeTransport::status(404));

        $this->check($link)->assertOk();

        $after = $link->fresh();
        $this->assertTrue($after->updated_at->equalTo($before->updated_at));
        $this->assertSame($before->getRawOriginal('search_text'), $after->getRawOriginal('search_text'));
    }

    public function test_the_link_object_reports_the_health_state(): void
    {
        $link = $this->link();
        $this->withToken($this->token)->getJson('/api/v1/links/'.$link->id)->assertJsonPath('data.link.health', ['is_broken' => false, 'last_checked_at' => null]);

        $this->transport->on('https://example.com/page', FakeTransport::status(404));
        $this->check($link)->assertOk();
        $this->resetAuth();

        $this->withToken($this->token)->getJson('/api/v1/links/'.$link->id)
            ->assertJsonPath('data.link.health.is_broken', true)
            ->assertJsonPath('data.link.health.last_checked_at', fn ($v) => is_string($v));
    }

    public function test_another_users_link_is_a_404_and_nothing_is_requested(): void
    {
        $theirs = $this->link('https://example.com/theirs', User::factory()->create());
        $this->transport->on('https://example.com/theirs', FakeTransport::status(200));

        $this->assertApiError($this->check($theirs), 404, 'NOT_FOUND');
        $this->assertApiError($this->withToken($this->token)->postJson('/api/v1/links/01jc3k9m4x7v2b8n5q6r0s1t2w/check-health'), 404, 'NOT_FOUND');
        $this->assertApiError($this->withToken($this->token)->postJson('/api/v1/links/nope/check-health'), 404, 'NOT_FOUND');
        $this->resetAuth();
        $this->flushHeaders();
        $this->assertApiError($this->postJson('/api/v1/links/'.$theirs->id.'/check-health'), 401, 'UNAUTHENTICATED');

        $this->assertSame([], $this->transport->requests);
        $this->assertNull($theirs->fresh()->last_checked_at);
    }

    public function test_the_check_is_limited_to_ten_per_minute_per_user(): void
    {
        $link = $this->link();
        $this->transport->on('https://example.com/page', FakeTransport::status(200));

        for ($i = 0; $i < 10; $i++) {
            $this->check($link)->assertOk();
        }

        $this->assertApiError($this->check($link), 429, 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_logs_never_contain_the_checked_url(): void
    {
        $link = $this->link('https://example.com/secret-path?token=abc123');
        $this->transport->on('https://example.com/secret-path?token=abc123', FakeTransport::status(404));
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });

        $this->check($link)->assertOk();

        $this->assertStringNotContainsString('abc123', implode("\n", $logged));
    }

    // ------------------------------------------------------------------ GET /links/broken

    public function test_the_broken_list_has_only_the_users_broken_links_newest_first(): void
    {
        $ok = $this->link('https://example.com/ok');
        $old = $this->link('https://example.com/old');
        $new = $this->link('https://example.com/new');
        $theirs = $this->link('https://example.com/theirs', User::factory()->create());
        Link::query()->whereKey($old->id)->update(['is_broken' => true, 'created_at' => now()->subDay()]);
        Link::query()->whereKey($new->id)->update(['is_broken' => true]);
        Link::query()->whereKey($theirs->id)->update(['is_broken' => true]);

        $response = $this->withToken($this->token)->getJson('/api/v1/links/broken')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'health', 'metadata', 'ai', 'collections', 'tags']], 'meta' => ['per_page', 'has_more', 'next_cursor', 'total']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.has_more', false);

        $this->assertSame([$new->id, $old->id], array_column($response->json('data'), 'id'));
        $this->assertNotContains($ok->id, array_column($response->json('data'), 'id'));
        $this->assertNotContains($theirs->id, array_column($response->json('data'), 'id'));
        $this->assertTrue($response->json('data.0.health.is_broken'));
    }

    public function test_the_broken_list_is_paginated_with_a_cursor(): void
    {
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $link = $this->link("https://example.com/b$i");
            Link::query()->whereKey($link->id)->update(['is_broken' => true, 'created_at' => now()->subHours(10 - $i)]);
            $ids[] = $link->id;
        }

        $first = $this->withToken($this->token)->getJson('/api/v1/links/broken?limit=2')->assertOk()->assertJsonPath('meta.has_more', true)->assertJsonPath('meta.total', 5);
        $this->resetAuth();
        $second = $this->withToken($this->token)->getJson('/api/v1/links/broken?limit=2&cursor='.urlencode($first->json('meta.next_cursor')))->assertOk()->assertJsonMissingPath('meta.total');
        $this->resetAuth();
        $third = $this->withToken($this->token)->getJson('/api/v1/links/broken?limit=2&cursor='.urlencode($second->json('meta.next_cursor')))->assertOk()->assertJsonPath('meta.has_more', false);

        $all = array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'), array_column($third->json('data'), 'id'));
        $this->assertSame(array_reverse($ids), $all);
    }

    public function test_the_broken_list_validates_its_parameters_and_requires_a_token(): void
    {
        $this->assertApiError($this->withToken($this->token)->getJson('/api/v1/links/broken?limit=0'), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->withToken($this->token)->getJson('/api/v1/links/broken?limit=500'), 422, 'VALIDATION_FAILED');
        $this->assertApiError($this->withToken($this->token)->getJson('/api/v1/links/broken?cursor=garbage'), 422, 'INVALID_CURSOR');
        $this->resetAuth();
        $this->flushHeaders();
        $this->assertApiError($this->getJson('/api/v1/links/broken'), 401, 'UNAUTHENTICATED');
    }

    public function test_a_cursor_from_the_normal_list_does_not_work_on_the_broken_list(): void
    {
        $this->link('https://example.com/one');
        $this->link('https://example.com/two');
        $cursor = $this->withToken($this->token)->getJson('/api/v1/links?limit=1')->json('meta.next_cursor');
        $this->resetAuth();

        $this->assertApiError($this->withToken($this->token)->getJson('/api/v1/links/broken?cursor='.urlencode($cursor)), 422, 'INVALID_CURSOR');
    }
}
