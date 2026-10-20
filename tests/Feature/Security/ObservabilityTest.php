<?php

namespace Tests\Feature\Security;

use App\Jobs\FetchLinkMetadata;
use App\Models\Link;
use App\Models\User;
use App\Services\Metadata\HttpTransport;
use App\Services\Metadata\MetadataProcessor;
use App\Services\Metadata\Target;
use App\Services\Metadata\TransportResponse;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use RuntimeException;
use Tests\Support\FakeTransport;
use Tests\TestCase;

/**
 * Request IDs in logs, failed-job and backlog visibility, and a log audit over
 * every kind of flow: no passwords, tokens, reset tokens, e-mails or full URLs
 * (security-plan.md "Logging and privacy", SEC-017).
 */
class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $logged = [];

    private function captureLogs(): void
    {
        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    public function test_reported_errors_carry_the_request_id_shown_to_the_client(): void
    {
        config(['logging.default' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        Route::middleware('api')->prefix('api/v1/_test')->get('/crash', fn () => throw new RuntimeException('boom'));

        $requestId = $this->getJson('/api/v1/_test/crash')->assertStatus(500)->json('error.request_id');

        $records = Log::channel('capture')->getLogger()->getHandlers()[0]->getRecords();
        $this->assertNotEmpty($records);
        $this->assertStringContainsString($requestId, json_encode(array_map(fn ($r) => [$r->context, $r->extra], $records)));
    }

    public function test_database_errors_are_logged_without_sql_or_bound_values(): void
    {
        $this->captureLogs();
        Route::middleware('api')->prefix('api/v1/_test')->get('/db', fn () => DB::select('select * from missing_table where email = ?', ['victim@example.test']));

        $response = $this->getJson('/api/v1/_test/db');

        $this->assertApiError($response, 500, 'SERVER_ERROR');
        $all = implode("\n", $this->logged);
        $this->assertStringContainsString('Database query failed', $all);
        $this->assertStringContainsString('QueryException', $all);
        $this->assertStringNotContainsString('victim@example.test', $all);
        $this->assertStringNotContainsString('missing_table', $all);
        $this->assertStringNotContainsString('select ', strtolower($all));
    }

    public function test_failed_jobs_are_logged_without_payload_or_urls(): void
    {
        $this->captureLogs();
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn(FetchLinkMetadata::class);
        $job->shouldReceive('getQueue')->andReturn('metadata');

        event(new JobFailed('database', $job, new RuntimeException('failed fetching https://example.com/private?token=abc')));

        $all = implode("\n", $this->logged);
        $this->assertStringContainsString('Queue job failed', $all);
        $this->assertStringContainsString('metadata', $all);
        $this->assertStringContainsString('RuntimeException', $all);
        $this->assertStringNotContainsString('example.com', $all);
        $this->assertStringNotContainsString('token=abc', $all);
    }

    public function test_queue_backlog_is_logged_as_a_warning(): void
    {
        $this->captureLogs();

        event(new QueueBusy('database', 'metadata', 250));

        $this->assertStringContainsString('Queue backlog', implode("\n", $this->logged));
        $this->assertStringContainsString('250', implode("\n", $this->logged));
    }

    public function test_backlog_monitoring_runs_every_minute_for_both_queues(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'queue:monitor'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertStringContainsString(':metadata,', $event->command);
        $this->assertStringContainsString(':default', $event->command);
        $this->assertStringContainsString('--max=100', $event->command);
    }

    public function test_logs_never_contain_secrets_emails_or_urls_across_all_flows(): void
    {
        $this->captureLogs();
        $secrets = ['audit-password-1', 'audit-password-2', 'audit-wrong-pw'];

        $register = $this->postJson('/api/v1/auth/register', [
            'display_name' => 'Audit', 'email' => 'audit@example.test', 'password' => $secrets[0],
            'password_confirmation' => $secrets[0], 'terms_accepted' => true,
        ])->assertCreated();
        $token = $register->json('data.token.access_token');
        $this->postJson('/api/v1/auth/login', ['email' => 'audit@example.test', 'password' => $secrets[2]])->assertStatus(401);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'audit@example.test'])->assertOk();
        $resetToken = Password::broker()->createToken(User::sole());

        $auth = ['Authorization' => 'Bearer '.$token];
        $url = 'https://private-host.example/very/private/path?secret=audit-url-secret';
        $link = $this->postJson('/api/v1/links', ['url' => $url, 'note' => 'audit private note', 'tags' => ['audit-tag']], $auth)->assertCreated()->json('data.link');
        $this->postJson('/api/v1/links/preview', ['url' => $url], $auth)->assertOk();
        $this->postJson('/api/v1/links', ['url' => 'javascript:alert(1)'], $auth)->assertStatus(422);
        $this->postJson('/api/v1/links', ['url' => $url], $auth)->assertStatus(409);
        $this->postJson("/api/v1/links/{$link['id']}/metadata/refresh", [], $auth)->assertStatus(202);

        // The metadata job: a permanent failure, a blocked target, then a crash that mentions the URL.
        $this->transport->on('https://private-host.example/very/private/path?secret=audit-url-secret', FakeTransport::status(404));
        $row = Link::find($link['id']);
        (new FetchLinkMetadata($row->id, $row->metadata_request_id))->handle(app(MetadataProcessor::class));
        $row->markMetadataPending();
        $row->save();
        $this->dns->records['private-host.example'] = ['10.0.0.1'];
        (new FetchLinkMetadata($row->id, $row->metadata_request_id))->handle(app(MetadataProcessor::class));
        $row->refresh()->markMetadataPending();
        $row->save();
        $this->app->instance(HttpTransport::class, new class implements HttpTransport
        {
            public function request(Target $target, float $timeoutSeconds, int $maxBytes): TransportResponse
            {
                throw new RuntimeException('crash at https://private-host.example/very/private/path?secret=audit-url-secret');
            }
        });
        $this->dns->records['private-host.example'] = ['93.184.216.34'];
        (new FetchLinkMetadata($row->id, $row->metadata_request_id))->handle(app(MetadataProcessor::class));

        $this->getJson('/api/v1/me/export', $auth)->assertOk()->streamedContent();
        $this->putJson('/api/v1/me/password', ['current_password' => $secrets[0], 'password' => $secrets[1], 'password_confirmation' => $secrets[1]], $auth)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'audit@example.test', 'token' => $resetToken, 'password' => $secrets[0], 'password_confirmation' => $secrets[0]])->assertOk();
        $final = $this->postJson('/api/v1/auth/login', ['email' => 'audit@example.test', 'password' => $secrets[0]])->json('data.token.access_token');
        $this->app['auth']->forgetGuards();
        $this->deleteJson('/api/v1/me', ['password' => $secrets[0]], ['Authorization' => 'Bearer '.$final])->assertNoContent();

        $this->assertNotEmpty($this->logged, 'The flows must have produced log entries to audit.');
        $all = implode("\n", $this->logged);
        $forbidden = [...$secrets, $resetToken, $token, explode('|', $token)[1], $final, explode('|', $final)[1], 'audit@example.test',
            'audit private note', 'audit-tag', 'audit-url-secret', 'very/private', 'https://', 'http://', 'Bearer '];
        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $all, 'Log leaked: '.substr($needle, 0, 14));
        }
        $this->assertStringContainsString('Metadata lookup failed', $all, 'The audited flows exercised the metadata logging.');
        $this->assertStringContainsString('private-host.example', $all, 'Only the domain may appear.');
    }
}
