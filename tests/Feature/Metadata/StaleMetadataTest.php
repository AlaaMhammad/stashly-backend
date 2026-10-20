<?php

namespace Tests\Feature\Metadata;

use App\Jobs\FetchLinkMetadata;
use App\Models\Link;
use App\Models\User;
use App\Services\Metadata\MetadataProcessor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTransport;
use Tests\TestCase;

/**
 * DEC-006: pending metadata older than 15 minutes becomes failed / STALE_PENDING.
 */
class StaleMetadataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function pendingLink(string $path): Link
    {
        return $this->saveLink($this->user, "https://example.com/$path");
    }

    public function test_pending_older_than_15_minutes_becomes_failed_stale_pending(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $stuck = $this->pendingLink('stuck');
        $requestId = $stuck->fresh()->metadata_request_id;

        $this->travelTo('2026-10-20 12:15:01');
        $this->artisan('links:expire-stale-metadata')->expectsOutput('Expired 1 stale pending metadata lookup(s).')->assertSuccessful();

        $link = $stuck->fresh();
        $this->assertSame('failed', $link->metadata_status);
        $this->assertSame('STALE_PENDING', $link->metadata_error_code);
        $this->assertNotNull($link->metadata_fetched_at);
        $this->assertNull($link->metadata_pending_since);
        $this->assertNull($link->metadata_request_id);
        $this->assertNotSame($requestId, $link->metadata_request_id);
    }

    public function test_pending_for_less_than_15_minutes_is_left_alone(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $fresh = $this->pendingLink('fresh');

        $this->travelTo('2026-10-20 12:14:59');
        $this->assertSame(0, app(MetadataProcessor::class)->expireStale());

        $this->assertSame('pending', $fresh->fresh()->metadata_status);
    }

    public function test_a_scheduled_retry_is_not_stuck_until_15_minutes_after_it_was_due(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $link = $this->pendingLink('waiting');
        $this->transport->on('https://example.com/waiting', FakeTransport::status(503));
        (new FetchLinkMetadata($link->id, $link->fresh()->metadata_request_id, 3))->handle(app(MetadataProcessor::class));
        // Attempt 3 failed: the next retry is due at 14:00.

        $this->travelTo('2026-10-20 13:50:00');
        $this->assertSame(0, app(MetadataProcessor::class)->expireStale(), 'Still waiting for its retry.');

        $this->travelTo('2026-10-20 14:15:01');
        $this->assertSame(1, app(MetadataProcessor::class)->expireStale());
        $this->assertSame('STALE_PENDING', $link->fresh()->metadata_error_code);
    }

    public function test_finished_links_are_never_touched(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $ready = $this->pendingLink('ready');
        $failed = $this->pendingLink('failed');
        $this->transport->on('https://example.com/ready', FakeTransport::html('<title>x</title>'));
        $this->transport->on('https://example.com/failed', FakeTransport::status(404));
        foreach ([$ready, $failed] as $link) {
            (new FetchLinkMetadata($link->id, $link->fresh()->metadata_request_id))->handle(app(MetadataProcessor::class));
        }

        $this->travelTo('2026-10-21 12:00:00');
        $this->assertSame(0, app(MetadataProcessor::class)->expireStale());

        $this->assertSame('ready', $ready->fresh()->metadata_status);
        $this->assertSame('HTTP_404', $failed->fresh()->metadata_error_code);
    }

    public function test_a_pending_link_without_a_clock_is_expired(): void
    {
        $legacy = $this->pendingLink('legacy');
        Link::whereKey($legacy->id)->update(['metadata_pending_since' => null]);

        $this->assertSame(1, app(MetadataProcessor::class)->expireStale());
    }

    public function test_a_late_job_cannot_resurrect_an_expired_lookup(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $link = $this->pendingLink('late');
        $requestId = $link->fresh()->metadata_request_id;
        $this->transport->on('https://example.com/late', FakeTransport::html('<title>Too late</title>'));

        $this->travelTo('2026-10-20 12:20:00');
        app(MetadataProcessor::class)->expireStale();
        (new FetchLinkMetadata($link->id, $requestId))->handle(app(MetadataProcessor::class));

        $this->assertSame('failed', $link->fresh()->metadata_status);
        $this->assertNull($link->fresh()->metadata_title);
        $this->assertSame([], $this->transport->requests);
    }

    public function test_a_refresh_after_expiry_starts_a_new_lookup(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $link = $this->pendingLink('again');
        $this->travelTo('2026-10-20 12:30:00');
        app(MetadataProcessor::class)->expireStale();

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/links/{$link->id}/metadata/refresh")->assertStatus(202);

        $this->assertSame('pending', $link->fresh()->metadata_status);
        $this->assertNull($link->fresh()->metadata_error_code);
        $this->assertNotNull($link->fresh()->metadata_request_id);
    }

    public function test_cleanup_runs_every_five_minutes(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('links:expire-stale-metadata')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'links:expire-stale-metadata'));
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
