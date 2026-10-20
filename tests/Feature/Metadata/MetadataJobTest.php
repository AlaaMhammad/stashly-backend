<?php

namespace Tests\Feature\Metadata;

use App\Jobs\FetchLinkMetadata;
use App\Models\Link;
use App\Models\User;
use App\Services\Metadata\FetchFailure;
use App\Services\Metadata\HttpTransport;
use App\Services\Metadata\MetadataProcessor;
use App\Services\Metadata\Target;
use App\Services\Metadata\TransportResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\FakeTransport;
use Tests\TestCase;

/**
 * FetchLinkMetadata: state machine, retries, user-data protection, safe logs
 * (contract 4.2, features/metadata-jobs.md, SEC-011..014, SEC-017).
 */
class MetadataJobTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://example.com/post';

    private const PAGE = '<html><head><title>Fetched title</title>
        <meta property="og:description" content="Fetched description">
        <meta property="og:site_name" content="Example"><meta property="og:image" content="https://cdn.example.com/i.jpg">
        <meta property="og:type" content="article"></head></html>';

    private User $user;

    private Link $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->link = $this->saveLink($this->user, self::URL, ['title' => 'My title', 'note' => 'My note']);
        Queue::fake();
    }

    private function runJob(?Link $link = null, int $attempt = 1, ?string $requestId = null): void
    {
        $link ??= $this->link;
        $job = new FetchLinkMetadata($link->id, $requestId ?? $link->fresh()->metadata_request_id, $attempt);
        $job->handle(app(MetadataProcessor::class));
    }

    public function test_success_stores_metadata_and_never_touches_the_users_title_or_note(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->transport->on(self::URL, FakeTransport::html(self::PAGE));

        $this->runJob();

        $link = $this->link->fresh();
        $this->assertSame('ready', $link->metadata_status);
        $this->assertSame('Fetched title', $link->metadata_title);
        $this->assertSame('Fetched description', $link->metadata_description);
        $this->assertSame('Example', $link->metadata_site_name);
        $this->assertSame('https://cdn.example.com/i.jpg', $link->metadata_preview_image_url);
        $this->assertSame('2026-10-20T12:00:00Z', $link->metadata_fetched_at->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertNull($link->metadata_error_code);
        $this->assertNull($link->metadata_pending_since);
        $this->assertSame('article', $link->content_type, 'og:type refines "other".');

        $this->assertSame('My title', $link->title);
        $this->assertSame('My note', $link->note);
        $this->assertSame('My title', $link->displayTitle());
        Queue::assertNothingPushed();
    }

    public function test_fetched_text_becomes_searchable_and_is_exposed_through_the_api(): void
    {
        $this->transport->on(self::URL, FakeTransport::html(self::PAGE));
        $this->runJob();
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/links?q=fetched%20description')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/links/{$this->link->id}")
            ->assertJsonPath('data.link.metadata.status', 'ready')
            ->assertJsonPath('data.link.metadata.title', 'Fetched title')
            ->assertJsonPath('data.link.metadata.preview_image_url', 'https://cdn.example.com/i.jpg')
            ->assertJsonPath('data.link.title', 'My title')
            ->assertJsonPath('data.link.display_title', 'My title');
    }

    public function test_the_fetched_title_is_the_display_title_when_the_user_gave_none(): void
    {
        $link = $this->saveLink($this->user, 'https://example.com/untitled');
        $this->transport->on('https://example.com/untitled', FakeTransport::html(self::PAGE));

        $this->runJob($link);

        $this->assertSame('Fetched title', $link->fresh()->displayTitle());
        $this->assertNull($link->fresh()->title);
    }

    public function test_redirects_are_followed_and_metadata_comes_from_the_final_page(): void
    {
        $this->transport
            ->on(self::URL, FakeTransport::redirect('https://www.example.org/final'))
            ->on('https://www.example.org/final', FakeTransport::html('<title>Final</title><link rel="icon" href="/f.ico">'));

        $this->runJob();

        $link = $this->link->fresh();
        $this->assertSame('Final', $link->metadata_title);
        $this->assertSame('https://www.example.org/f.ico', $link->metadata_favicon_url);
        $this->assertSame('example.com', $link->source_domain, 'The saved domain does not change.');
    }

    public function test_a_blocked_target_ends_in_blocked_without_retry(): void
    {
        $this->dns->records['example.com'] = ['10.0.0.9'];

        $this->runJob();

        $link = $this->link->fresh();
        $this->assertSame('blocked', $link->metadata_status);
        $this->assertSame('BLOCKED_HOST', $link->metadata_error_code);
        $this->assertNotNull($link->metadata_fetched_at);
        $this->assertSame([], $this->transport->requests);
        Queue::assertNothingPushed();
    }

    public function test_a_redirect_into_the_internal_network_ends_in_blocked(): void
    {
        $this->transport->on(self::URL, FakeTransport::redirect('http://169.254.169.254/latest/meta-data/'));

        $this->runJob();

        $this->assertSame('blocked', $this->link->fresh()->metadata_status);
        $this->assertSame('BLOCKED_HOST', $this->link->fresh()->metadata_error_code);
        $this->assertCount(1, $this->transport->requests);
    }

    public function test_permanent_failures_end_in_failed_without_retry(): void
    {
        foreach ([
            [FakeTransport::status(404), 'HTTP_404'],
            [FakeTransport::status(403), 'HTTP_403'],
            [FakeTransport::html('{}', 200, 'application/json'), 'NOT_HTML'],
            [FetchFailure::unreachable(), 'UNREACHABLE'],
            [FetchFailure::tls(), 'TLS_ERROR'],
        ] as [$response, $code]) {
            $link = $this->saveLink($this->user, 'https://example.com/'.strtolower($code));
            $this->transport->on('https://example.com/'.strtolower($code), $response);
            Queue::fake();

            $this->runJob($link);

            $fresh = $link->fresh();
            $this->assertSame('failed', $fresh->metadata_status, $code);
            $this->assertSame($code, $fresh->metadata_error_code);
            Queue::assertNothingPushed();
        }
    }

    public function test_temporary_failures_are_retried_after_2_minutes_15_minutes_and_2_hours_then_fail(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->transport->on(self::URL, FakeTransport::status(503));

        foreach ([[1, 120], [2, 900], [3, 7200]] as [$attempt, $delay]) {
            Queue::fake();
            $this->runJob($this->link, $attempt);

            $link = $this->link->fresh();
            $this->assertSame('pending', $link->metadata_status, "attempt $attempt keeps the link pending");
            $this->assertEquals(now()->addSeconds($delay), $link->metadata_pending_since, 'stale clock restarts at the retry time');
            Queue::assertPushed(FetchLinkMetadata::class, function (FetchLinkMetadata $job) use ($attempt, $delay) {
                return $job->attempt === $attempt + 1
                    && $job->delay === $delay
                    && $job->queue === 'metadata'
                    && $job->requestId === $this->link->fresh()->metadata_request_id;
            });
            Queue::assertPushed(FetchLinkMetadata::class, 1);
        }

        Queue::fake();
        $this->runJob($this->link, 4);

        $link = $this->link->fresh();
        $this->assertSame('failed', $link->metadata_status, 'At most four attempts.');
        $this->assertSame('HTTP_503', $link->metadata_error_code);
        $this->assertNull($link->metadata_pending_since);
        Queue::assertNothingPushed();
    }

    public function test_429_5xx_and_timeouts_are_temporary(): void
    {
        foreach ([FakeTransport::status(429), FakeTransport::status(500), FakeTransport::status(502), FetchFailure::timeout()] as $i => $response) {
            $link = $this->saveLink($this->user, "https://example.com/t$i");
            $this->transport->on("https://example.com/t$i", $response);
            Queue::fake();

            $this->runJob($link);

            $this->assertSame('pending', $link->fresh()->metadata_status);
            Queue::assertPushed(FetchLinkMetadata::class, fn ($job) => $job->attempt === 2 && $job->delay === 120);
        }
    }

    public function test_a_retry_that_succeeds_makes_the_link_ready(): void
    {
        $this->transport->on(self::URL, FakeTransport::status(503), FakeTransport::html(self::PAGE));

        $this->runJob($this->link, 1);
        $this->assertSame('pending', $this->link->fresh()->metadata_status);

        $this->runJob($this->link, 2);
        $link = $this->link->fresh();
        $this->assertSame('ready', $link->metadata_status);
        $this->assertSame(2, $link->metadata_attempts);
        $this->assertSame('Fetched title', $link->metadata_title);
    }

    public function test_a_job_from_a_superseded_lookup_does_nothing(): void
    {
        $oldRequest = $this->link->fresh()->metadata_request_id;
        $refreshed = $this->link->fresh();
        $refreshed->markMetadataPending();
        $refreshed->save();
        $this->transport->on(self::URL, FakeTransport::html(self::PAGE));

        $this->runJob($this->link, 1, $oldRequest);

        $this->assertSame('pending', $this->link->fresh()->metadata_status);
        $this->assertNull($this->link->fresh()->metadata_title);
        $this->assertSame([], $this->transport->requests, 'A superseded job must not even fetch.');
    }

    public function test_a_lookup_superseded_while_fetching_does_not_overwrite_newer_state(): void
    {
        $oldRequest = $this->link->fresh()->metadata_request_id;
        $link = $this->link;
        $racing = new class($link) implements HttpTransport
        {
            public function __construct(private Link $link) {}

            public function request(Target $target, float $timeoutSeconds, int $maxBytes): TransportResponse
            {
                // While this fetch is in flight, the user triggers a refresh.
                $fresh = Link::find($this->link->id);
                $fresh->markMetadataPending();
                $fresh->save();

                return FakeTransport::html('<title>Stale result</title>');
            }
        };
        $this->app->instance(HttpTransport::class, $racing);

        $this->runJob($this->link, 1, $oldRequest);

        $this->assertNull($this->link->fresh()->metadata_title, 'The stale result must be discarded.');
        $this->assertSame('pending', $this->link->fresh()->metadata_status);
    }

    public function test_a_deleted_link_is_skipped(): void
    {
        $requestId = $this->link->fresh()->metadata_request_id;
        $this->link->delete();

        $this->runJob($this->link, 1, $requestId);

        $this->assertSame([], $this->transport->requests);
    }

    public function test_a_link_that_is_no_longer_pending_is_not_touched(): void
    {
        $this->transport->on(self::URL, FakeTransport::html(self::PAGE));
        $this->runJob();
        $before = $this->link->fresh()->metadata_fetched_at;

        $this->transport->requests = [];
        $this->transport->on(self::URL, FakeTransport::html('<title>Second</title>'));
        $this->runJob();

        $this->assertSame('Fetched title', $this->link->fresh()->metadata_title);
        $this->assertSame([], $this->transport->requests);
        $this->assertEquals($before, $this->link->fresh()->metadata_fetched_at);
    }

    public function test_refresh_restarts_the_lookup_and_replaces_old_metadata(): void
    {
        $this->transport->on(self::URL, FakeTransport::html(self::PAGE));
        $this->runJob();
        Sanctum::actingAs($this->user);
        $this->transport->on(self::URL, FakeTransport::html('<title>Newer title</title>'));
        Queue::fake();

        $this->postJson("/api/v1/links/{$this->link->id}/metadata/refresh")->assertStatus(202)
            ->assertJsonPath('data.link.metadata.status', 'pending')
            ->assertJsonPath('data.link.metadata.title', 'Fetched title');

        $requestId = $this->link->fresh()->metadata_request_id;
        Queue::assertPushed(FetchLinkMetadata::class, fn ($job) => $job->requestId === $requestId && $job->attempt === 1);

        $this->runJob();
        $link = $this->link->fresh();
        $this->assertSame('ready', $link->metadata_status);
        $this->assertSame('Newer title', $link->metadata_title);
        $this->assertNull($link->metadata_description, 'Fields absent from the new page are cleared.');
        $this->assertSame('My title', $link->title);
    }

    public function test_saving_queues_the_job_on_the_metadata_queue_and_fetches_nothing_itself(): void
    {
        Sanctum::actingAs($this->user);
        Queue::fake();

        $id = $this->postJson('/api/v1/links', ['url' => 'https://example.com/new'])->assertCreated()->json('data.link.id');

        $link = Link::find($id);
        Queue::assertPushed(FetchLinkMetadata::class, fn ($job) => $job->queue === 'metadata'
            && $job->linkId === $id
            && $job->requestId === $link->metadata_request_id
            && $job->attempt === 1);
        $this->assertSame([], $this->transport->requests, 'Saving never waits for metadata.');
        $this->assertSame('pending', $link->metadata_status);
        $this->assertNotNull($link->metadata_pending_since);
    }

    public function test_the_job_is_configured_for_a_single_queue_attempt_and_a_hard_timeout(): void
    {
        $job = new FetchLinkMetadata('id', 'request');

        $this->assertSame(1, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame('metadata', $job->queue);
    }

    public function test_an_unexpected_crash_ends_in_failed_internal_error_without_leaking_details(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $this->app->instance(HttpTransport::class, new class implements HttpTransport
        {
            public function request(Target $target, float $timeoutSeconds, int $maxBytes): TransportResponse
            {
                throw new RuntimeException('boom https://example.com/secret-path?token=abc');
            }
        });

        $this->runJob();

        $this->assertSame('failed', $this->link->fresh()->metadata_status);
        $this->assertSame('INTERNAL_ERROR', $this->link->fresh()->metadata_error_code);
        $all = implode("\n", $logged);
        $this->assertStringContainsString('crashed', $all);
        $this->assertStringNotContainsString('secret-path', $all);
        $this->assertStringNotContainsString('token=abc', $all);
    }

    public function test_the_queue_failed_hook_marks_the_lookup_failed(): void
    {
        $requestId = $this->link->fresh()->metadata_request_id;

        (new FetchLinkMetadata($this->link->id, $requestId))->failed(new RuntimeException('worker killed'));

        $this->assertSame('failed', $this->link->fresh()->metadata_status);
        $this->assertSame('INTERNAL_ERROR', $this->link->fresh()->metadata_error_code);

        // A hook from an older lookup must not break a newer one.
        $newer = $this->saveLink($this->user, 'https://example.com/newer');
        (new FetchLinkMetadata($newer->id, 'someoldrequestid'))->failed(null);
        $this->assertSame('pending', $newer->fresh()->metadata_status);
    }

    public function test_logs_contain_the_domain_and_error_code_but_never_the_full_url(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $link = $this->saveLink($this->user, 'https://example.com/private/path?secret=1');
        $this->transport->on('https://example.com/private/path?secret=1', FakeTransport::status(404));

        $this->runJob($link);

        $all = implode("\n", $logged);
        $this->assertStringContainsString('HTTP_404', $all);
        $this->assertStringContainsString('example.com', $all);
        $this->assertStringContainsString($link->id, $all);
        $this->assertStringNotContainsString('private/path', $all);
        $this->assertStringNotContainsString('secret=1', $all);
        $this->assertStringNotContainsString('https://', $all);
    }
}
