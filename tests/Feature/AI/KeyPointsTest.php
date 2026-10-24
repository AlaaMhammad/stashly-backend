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
 * API-AI-003 POST /links/{link}/key-points (DEC-039). Gemini is always faked.
 */
class KeyPointsTest extends TestCase
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
        $this->link = $this->saveLink($this->user, 'https://example.com/post?token=SECRET123', ['title' => 'Laravel queues', 'note' => 'Workers pick up jobs']);
        $this->link->forceFill(['metadata_description' => 'Queues defer slow work. Retries make jobs reliable.'])->save();
        $this->token = $this->tokenFor($this->user);
    }

    private function keyPoints(string $query = '', ?Link $link = null, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->postJson('/api/v1/links/'.($link ?? $this->link)->id.'/key-points'.$query);
    }

    private function good(array $override = []): array
    {
        return $override + [
            'key_takeaways' => [
                ['point' => 'Queues defer work', 'explanation' => 'Slow tasks run outside the request.'],
                ['point' => 'Retries', 'explanation' => 'Failed jobs can be retried.'],
                ['point' => 'Workers', 'explanation' => 'Workers pick up jobs.'],
            ],
            'memorable_quotes' => ['Queues defer slow work.'],
            'estimated_read_time_minutes' => 5,
            'difficulty_level' => 'intermediate',
        ];
    }

    private function freshHttp(): void
    {
        Http::swap(new Factory);
    }

    // ------------------------------------------------------------------ success and caching

    public function test_returns_the_documented_shape_and_stores_the_result(): void
    {
        FakeGemini::reply($this->good());

        $this->keyPoints()->assertOk()->assertExactJson(['data' => [
            'link_id' => $this->link->id,
            'insights' => [
                'key_takeaways' => [
                    ['point' => 'Queues defer work', 'explanation' => 'Slow tasks run outside the request.'],
                    ['point' => 'Retries', 'explanation' => 'Failed jobs can be retried.'],
                    ['point' => 'Workers', 'explanation' => 'Workers pick up jobs.'],
                ],
                'memorable_quotes' => ['Queues defer slow work.'],
                'estimated_read_time_minutes' => 5,
                'difficulty_level' => 'intermediate',
            ],
        ]]);

        $this->assertSame(5, $this->link->fresh()->ai_insights['estimated_read_time_minutes']);
    }

    public function test_the_request_goes_to_gemini_with_a_schema_and_without_the_full_url(): void
    {
        FakeGemini::reply($this->good());
        $this->keyPoints()->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            $data = json_encode($r->data());

            return $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'
                && $r->header('x-goog-api-key') === [FakeGemini::KEY]
                && data_get($r->data(), 'generationConfig.responseSchema.required') === ['key_takeaways', 'memorable_quotes', 'estimated_read_time_minutes', 'difficulty_level']
                && str_contains(FakeGemini::prompt($r), 'Laravel queues') && str_contains(FakeGemini::prompt($r), 'Workers pick up jobs')
                && str_contains(FakeGemini::prompt($r), 'Queues defer slow work.') && str_contains(FakeGemini::prompt($r), 'DATA START')
                && ! str_contains($data, 'SECRET123') && ! str_contains($data, $this->user->email) && ! str_contains($data, $this->token);
        });
    }

    public function test_a_stored_result_is_returned_without_calling_gemini(): void
    {
        FakeGemini::reply($this->good());
        $first = $this->keyPoints()->assertOk()->json();
        $this->resetAuth();

        $this->keyPoints()->assertOk()->assertExactJson($first);
        $this->keyPoints('?refresh=false')->assertOk()->assertExactJson($first);

        Http::assertSentCount(1);
    }

    public function test_refresh_recomputes_and_replaces_the_stored_result(): void
    {
        FakeGemini::reply($this->good());
        $this->keyPoints()->assertOk();

        foreach (['true', '1'] as $value) {
            $this->freshHttp();
            FakeGemini::reply($this->good(['estimated_read_time_minutes' => 9, 'difficulty_level' => 'advanced']));
            $this->resetAuth();

            $this->keyPoints("?refresh=$value")->assertOk()->assertJsonPath('data.insights.difficulty_level', 'advanced');
            Http::assertSentCount(1);
        }

        $this->assertSame(9, $this->link->fresh()->ai_insights['estimated_read_time_minutes']);
    }

    public function test_a_failed_refresh_keeps_the_stored_result(): void
    {
        FakeGemini::reply($this->good());
        $this->keyPoints()->assertOk();

        $this->freshHttp();
        FakeGemini::timeout();
        $this->resetAuth();
        $this->assertApiError($this->keyPoints('?refresh=true'), 504, 'AI_TIMEOUT');

        $this->assertSame('intermediate', $this->link->fresh()->ai_insights['difficulty_level']);
    }

    public function test_a_stored_result_is_served_even_if_the_key_was_removed_later(): void
    {
        FakeGemini::reply($this->good());
        $this->keyPoints()->assertOk();

        FakeGemini::configure(null);
        $this->resetAuth();
        $this->keyPoints()->assertOk();
        $this->resetAuth();
        $this->assertApiError($this->keyPoints('?refresh=true'), 503, 'AI_SERVICE_UNAVAILABLE');
    }

    public function test_the_refresh_parameter_is_validated(): void
    {
        FakeGemini::reply($this->good());

        $this->assertApiError($this->keyPoints('?refresh=maybe'), 422, 'VALIDATION_FAILED');
        Http::assertNothingSent();
    }

    public function test_the_stored_insights_do_not_appear_in_the_normal_link_object(): void
    {
        FakeGemini::reply($this->good());
        $this->keyPoints()->assertOk();

        $body = $this->withToken($this->token)->getJson('/api/v1/links/'.$this->link->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('ai_insights', $body);
        $this->assertStringNotContainsString('key_takeaways', $body);
    }

    // ------------------------------------------------------------------ output guards

    public function test_quotes_must_occur_in_the_supplied_text(): void
    {
        FakeGemini::reply($this->good(['memorable_quotes' => [
            'Queues defer slow work.', '  queues   DEFER slow work.  ', 'Retries make jobs reliable.', 'A famous invented quote', 7, null, '', 'Workers pick up jobs',
        ]]));

        $this->keyPoints()->assertOk()->assertJsonPath('data.insights.memorable_quotes', [
            'Queues defer slow work.', 'Retries make jobs reliable.', 'Workers pick up jobs',
        ]);
    }

    public function test_at_most_three_quotes_and_five_takeaways_are_kept(): void
    {
        $takeaways = [];
        for ($i = 1; $i <= 8; $i++) {
            $takeaways[] = ['point' => "Point $i", 'explanation' => "Why $i"];
        }
        FakeGemini::reply($this->good([
            'key_takeaways' => $takeaways,
            'memorable_quotes' => ['Laravel queues', 'Queues defer slow work.', 'Retries make jobs reliable.', 'Workers pick up jobs'],
        ]));

        $response = $this->keyPoints()->assertOk();
        $this->assertCount(5, $response->json('data.insights.key_takeaways'));
        $this->assertCount(3, $response->json('data.insights.memorable_quotes'));
    }

    public function test_takeaways_without_a_point_are_dropped_and_non_arrays_ignored(): void
    {
        FakeGemini::reply($this->good(['key_takeaways' => [
            ['point' => '', 'explanation' => 'x'], 'text', ['explanation' => 'only'], ['point' => 'Real', 'explanation' => ['bad']], null,
        ]]));

        $this->keyPoints()->assertOk()->assertJsonPath('data.insights.key_takeaways', [['point' => 'Real', 'explanation' => '']]);
    }

    public function test_read_time_is_clamped_and_difficulty_is_normalized(): void
    {
        foreach ([[0, 1], [-4, 1], [9999, 240], ['7', 7], [4.6, 5]] as [$given, $expected]) {
            $this->freshHttp();
            FakeGemini::reply($this->good(['estimated_read_time_minutes' => $given, 'difficulty_level' => ' Advanced ']));
            $this->resetAuth();

            $this->keyPoints('?refresh=true')->assertOk()
                ->assertJsonPath('data.insights.estimated_read_time_minutes', $expected)
                ->assertJsonPath('data.insights.difficulty_level', 'advanced');
        }
    }

    public function test_unusable_output_is_a_502_and_nothing_is_stored(): void
    {
        $bad = [
            'no takeaways' => $this->good(['key_takeaways' => []]),
            'takeaways not a list' => $this->good(['key_takeaways' => 'text']),
            'bad difficulty' => $this->good(['difficulty_level' => 'expert']),
            'difficulty missing' => ['key_takeaways' => $this->good()['key_takeaways'], 'estimated_read_time_minutes' => 3],
            'read time not numeric' => $this->good(['estimated_read_time_minutes' => 'soon']),
            'read time missing' => ['key_takeaways' => $this->good()['key_takeaways'], 'difficulty_level' => 'beginner'],
        ];

        foreach ($bad as $label => $payload) {
            $this->freshHttp();
            FakeGemini::reply($payload);
            $this->assertApiError($this->keyPoints(), 502, 'AI_INVALID_RESPONSE');
            $this->assertNull($this->link->fresh()->ai_insights, $label);
        }

        $this->freshHttp();
        FakeGemini::replyText('Sure, here are the key points!');
        $this->assertApiError($this->keyPoints(), 502, 'AI_INVALID_RESPONSE');
    }

    // ------------------------------------------------------------------ authorization and failures

    public function test_another_users_link_is_a_404_and_nothing_is_sent(): void
    {
        $theirs = $this->saveLink(User::factory()->create(), 'https://example.com/theirs', ['title' => 'Theirs']);
        FakeGemini::reply($this->good());

        $this->assertApiError($this->keyPoints('', $theirs), 404, 'NOT_FOUND');

        Http::assertNothingSent();
        $this->assertNull($theirs->fresh()->ai_insights);
    }

    public function test_another_users_stored_result_is_never_returned(): void
    {
        $owner = User::factory()->create();
        $theirs = $this->saveLink($owner, 'https://example.com/theirs', ['title' => 'Theirs']);
        $theirs->forceFill(['ai_insights' => $this->good()])->save();

        $this->assertApiError($this->keyPoints('', $theirs), 404, 'NOT_FOUND');
    }

    public function test_unknown_and_malformed_ids_are_404_and_anonymous_is_401(): void
    {
        FakeGemini::reply($this->good());

        $this->assertApiError($this->withToken($this->token)->postJson('/api/v1/links/01jc3k9m4x7v2b8n5q6r0s1t2w/key-points'), 404, 'NOT_FOUND');
        $this->assertApiError($this->withToken($this->token)->postJson('/api/v1/links/nope/key-points'), 404, 'NOT_FOUND');
        $this->resetAuth();
        $this->flushHeaders();
        $this->assertApiError($this->postJson('/api/v1/links/'.$this->link->id.'/key-points'), 401, 'UNAUTHENTICATED');
        Http::assertNothingSent();
    }

    public function test_a_missing_key_gives_503_and_no_outgoing_request(): void
    {
        foreach ([null, ''] as $key) {
            FakeGemini::configure($key);
            Http::fake();

            $this->assertApiError($this->keyPoints(), 503, 'AI_SERVICE_UNAVAILABLE');
            Http::assertNothingSent();
        }
    }

    public function test_timeouts_and_provider_errors_map_to_504_and_503(): void
    {
        FakeGemini::timeout();
        $this->assertApiError($this->keyPoints(), 504, 'AI_TIMEOUT');

        foreach ([403, 429, 500] as $status) {
            $this->freshHttp();
            FakeGemini::status($status, ['error' => ['message' => FakeGemini::KEY]]);
            $response = $this->assertApiError($this->keyPoints(), 503, 'AI_SERVICE_UNAVAILABLE');
            $this->assertStringNotContainsString(FakeGemini::KEY, $response->getContent());
        }

        $this->freshHttp();
        FakeGemini::unreachable();
        $this->assertApiError($this->keyPoints(), 503, 'AI_SERVICE_UNAVAILABLE');
        $this->assertNull($this->link->fresh()->ai_insights);
    }

    public function test_a_link_with_nothing_to_analyse_is_a_422_without_calling_the_provider(): void
    {
        $bare = $this->saveLink($this->user, 'https://example.com/bare');
        FakeGemini::reply($this->good());

        $this->assertApiError($this->keyPoints('', $bare), 422, 'VALIDATION_FAILED');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ hygiene

    public function test_logs_hold_no_key_url_or_content(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });

        foreach ([fn () => FakeGemini::timeout(), fn () => FakeGemini::status(403, ['error' => ['message' => FakeGemini::KEY]]), fn () => FakeGemini::replyText('x')] as $arrange) {
            $this->freshHttp();
            $arrange();
            $this->keyPoints();
        }

        $all = implode("\n", $logged);
        $this->assertStringContainsString('AI request failed.', $all);
        foreach ([FakeGemini::KEY, $this->token, 'SECRET123', 'Laravel queues', 'Workers pick up jobs'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_it_shares_the_per_user_ai_rate_limit(): void
    {
        FakeGemini::reply($this->good());

        for ($i = 0; $i < 10; $i++) {
            $this->keyPoints()->assertOk();
        }

        $this->assertApiError($this->keyPoints(), 429, 'RATE_LIMITED')->assertHeader('Retry-After');
    }

    public function test_deleting_the_link_removes_the_stored_insights(): void
    {
        FakeGemini::reply($this->good());
        $this->keyPoints()->assertOk();

        $this->withToken($this->token)->deleteJson('/api/v1/links/'.$this->link->id)->assertNoContent();

        $this->assertSame(0, Link::count());
    }
}
