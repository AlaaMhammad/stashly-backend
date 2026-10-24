<?php

namespace Tests\Feature\AI;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGemini;
use Tests\TestCase;

/**
 * API-AI-004 POST /links/{link}/action-plan (DEC-039). Gemini is always faked.
 */
class ActionPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Link $link;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        FakeGemini::configure();
        $this->user = User::factory()->create();
        $this->link = $this->saveLink($this->user, 'https://example.com/howto?token=SECRET123', ['title' => 'Set up a queue worker', 'note' => 'for the invoices app']);
        $this->token = $this->tokenFor($this->user);
    }

    private function plan(string $query = '', ?Link $link = null)
    {
        return $this->withToken($this->token)->postJson('/api/v1/links/'.($link ?? $this->link)->id.'/action-plan'.$query);
    }

    private function good(array $override = []): array
    {
        return $override + [
            'objective' => 'Run queue workers reliably.',
            'steps' => [
                ['step_number' => 9, 'title' => 'Install Supervisor', 'description' => 'Keeps the worker alive.', 'action_item' => 'Install the package'],
                ['step_number' => 1, 'title' => 'Start the worker', 'description' => 'Process jobs.', 'action_item' => 'Run queue:work'],
            ],
        ];
    }

    private function freshHttp(): void
    {
        Http::swap(new Factory);
    }

    public function test_returns_the_documented_shape_with_server_side_step_numbers(): void
    {
        FakeGemini::reply($this->good());

        $this->plan()->assertOk()->assertExactJson(['data' => [
            'link_id' => $this->link->id,
            'action_plan' => [
                'objective' => 'Run queue workers reliably.',
                'steps' => [
                    ['step_number' => 1, 'title' => 'Install Supervisor', 'description' => 'Keeps the worker alive.', 'action_item' => 'Install the package'],
                    ['step_number' => 2, 'title' => 'Start the worker', 'description' => 'Process jobs.', 'action_item' => 'Run queue:work'],
                ],
            ],
        ]]);

        $this->assertSame('Run queue workers reliably.', $this->link->fresh()->ai_action_plan['objective']);
    }

    public function test_the_request_has_a_schema_and_no_full_url(): void
    {
        FakeGemini::reply($this->good());
        $this->plan()->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => data_get($r->data(), 'generationConfig.responseSchema.required') === ['objective', 'steps']
            && str_contains(FakeGemini::prompt($r), 'Set up a queue worker') && str_contains(FakeGemini::prompt($r), 'for the invoices app')
            && $r->header('x-goog-api-key') === [FakeGemini::KEY]
            && ! str_contains(json_encode($r->data()), 'SECRET123') && ! str_contains(json_encode($r->data()), $this->user->email));
    }

    public function test_the_result_is_cached_until_refresh_is_requested(): void
    {
        FakeGemini::reply($this->good());
        $first = $this->plan()->assertOk()->json();
        $this->resetAuth();
        $this->plan()->assertOk()->assertExactJson($first);
        Http::assertSentCount(1);

        $this->freshHttp();
        FakeGemini::reply($this->good(['objective' => 'A new objective']));
        $this->resetAuth();
        $this->plan('?refresh=true')->assertOk()->assertJsonPath('data.action_plan.objective', 'A new objective');
        Http::assertSentCount(1);

        $this->resetAuth();
        $this->plan()->assertOk()->assertJsonPath('data.action_plan.objective', 'A new objective');
        $this->assertSame('A new objective', $this->link->fresh()->ai_action_plan['objective']);
    }

    public function test_a_failed_refresh_keeps_the_stored_plan_and_a_bad_flag_is_rejected(): void
    {
        FakeGemini::reply($this->good());
        $this->plan()->assertOk();

        $this->freshHttp();
        FakeGemini::timeout();
        $this->resetAuth();
        $this->assertApiError($this->plan('?refresh=true'), 504, 'AI_TIMEOUT');
        $this->assertSame('Run queue workers reliably.', $this->link->fresh()->ai_action_plan['objective']);

        $this->resetAuth();
        $this->assertApiError($this->plan('?refresh=perhaps'), 422, 'VALIDATION_FAILED');
    }

    public function test_steps_are_cleaned_and_limited(): void
    {
        $steps = [['title' => '', 'description' => 'no title', 'action_item' => 'x'], 'text', null];
        for ($i = 1; $i <= 20; $i++) {
            $steps[] = ['title' => "Step $i", 'description' => ['bad'], 'action_item' => str_repeat('a', 500)];
        }
        FakeGemini::reply($this->good(['steps' => $steps, 'objective' => "  Do\n it  "]));

        $response = $this->plan()->assertOk()->assertJsonPath('data.action_plan.objective', 'Do it');
        $out = $response->json('data.action_plan.steps');

        $this->assertCount(15, $out);
        $this->assertSame(range(1, 15), array_column($out, 'step_number'));
        $this->assertSame('Step 1', $out[0]['title']);
        $this->assertSame('', $out[0]['description']);
        $this->assertSame(300, mb_strlen($out[0]['action_item']));
    }

    public function test_unusable_output_is_a_502_and_nothing_is_stored(): void
    {
        $bad = [
            'no steps' => $this->good(['steps' => []]),
            'steps not a list' => $this->good(['steps' => 'do it']),
            'no objective' => $this->good(['objective' => '  ']),
            'objective not text' => $this->good(['objective' => ['x']]),
            'all steps untitled' => $this->good(['steps' => [['title' => ' ']]]),
        ];

        foreach ($bad as $label => $payload) {
            $this->freshHttp();
            FakeGemini::reply($payload);
            $this->resetAuth();
            $this->assertApiError($this->plan(), 502, 'AI_INVALID_RESPONSE');
            $this->assertNull($this->link->fresh()->ai_action_plan, $label);
        }

        $this->freshHttp();
        FakeGemini::replyText('Step one: relax.');
        $this->resetAuth();
        $this->assertApiError($this->plan(), 502, 'AI_INVALID_RESPONSE');
    }

    public function test_ownership_authentication_and_ids(): void
    {
        $theirs = $this->saveLink(User::factory()->create(), 'https://example.com/theirs', ['title' => 'Theirs']);
        $theirs->forceFill(['ai_action_plan' => $this->good()])->save();
        FakeGemini::reply($this->good());

        $this->assertApiError($this->plan('', $theirs), 404, 'NOT_FOUND');
        $this->assertApiError($this->withToken($this->token)->postJson('/api/v1/links/01jc3k9m4x7v2b8n5q6r0s1t2w/action-plan'), 404, 'NOT_FOUND');
        $this->assertApiError($this->withToken($this->token)->postJson('/api/v1/links/nope/action-plan'), 404, 'NOT_FOUND');
        $this->resetAuth();
        $this->flushHeaders();
        $this->assertApiError($this->postJson('/api/v1/links/'.$this->link->id.'/action-plan'), 401, 'UNAUTHENTICATED');
        Http::assertNothingSent();
    }

    public function test_a_missing_key_is_a_503_without_a_request_but_a_stored_plan_is_still_served(): void
    {
        FakeGemini::configure(null);
        Http::fake();
        $this->assertApiError($this->plan(), 503, 'AI_SERVICE_UNAVAILABLE');
        Http::assertNothingSent();

        $this->link->forceFill(['ai_action_plan' => $this->good()])->save();
        $this->resetAuth();
        $this->plan()->assertOk()->assertJsonPath('data.action_plan.objective', 'Run queue workers reliably.');
    }

    public function test_timeouts_and_provider_errors(): void
    {
        FakeGemini::timeout();
        $this->assertApiError($this->plan(), 504, 'AI_TIMEOUT');

        foreach ([401, 429, 500] as $status) {
            $this->freshHttp();
            FakeGemini::status($status, ['error' => ['message' => FakeGemini::KEY]]);
            $this->resetAuth();
            $response = $this->assertApiError($this->plan(), 503, 'AI_SERVICE_UNAVAILABLE');
            $this->assertStringNotContainsString(FakeGemini::KEY, $response->getContent());
        }
    }

    public function test_a_link_with_nothing_to_analyse_is_a_422(): void
    {
        $bare = $this->saveLink($this->user, 'https://example.com/bare');
        FakeGemini::reply($this->good());

        $this->assertApiError($this->plan('', $bare), 422, 'VALIDATION_FAILED');
        Http::assertNothingSent();
    }

    public function test_logs_hold_no_key_url_or_content(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });

        foreach ([fn () => FakeGemini::timeout(), fn () => FakeGemini::status(403, ['error' => ['message' => FakeGemini::KEY]])] as $arrange) {
            $this->freshHttp();
            $arrange();
            $this->resetAuth();
            $this->plan();
        }

        $all = implode("\n", $logged);
        $this->assertStringContainsString('AI request failed.', $all);
        foreach ([FakeGemini::KEY, $this->token, 'SECRET123', 'Set up a queue worker', 'invoices app'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_it_shares_the_ai_rate_limit(): void
    {
        FakeGemini::reply($this->good());

        for ($i = 0; $i < 10; $i++) {
            $this->plan()->assertOk();
        }

        $this->assertApiError($this->plan(), 429, 'RATE_LIMITED')->assertHeader('Retry-After');
    }
}
