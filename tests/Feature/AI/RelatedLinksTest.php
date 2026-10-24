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
 * API-AI-005 GET /links/{link}/related (DEC-039). Gemini is always faked.
 */
class RelatedLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Link $source;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        FakeGemini::configure();
        $this->user = User::factory()->create();
        $this->source = $this->saveLink($this->user, 'https://example.com/source?token=SECRET123', ['title' => 'Laravel queues', 'note' => 'workers']);
        $this->token = $this->tokenFor($this->user);
    }

    private function related(?Link $link = null)
    {
        return $this->withToken($this->token)->getJson('/api/v1/links/'.($link ?? $this->source)->id.'/related');
    }

    private function other(string $title, array $extra = [], ?User $owner = null): Link
    {
        return $this->saveLink($owner ?? $this->user, 'https://example.com/'.str()->slug($title), ['title' => $title] + $extra);
    }

    private function pick(array $items): void
    {
        FakeGemini::reply(['related' => $items]);
    }

    private function freshHttp(): void
    {
        Http::swap(new Factory);
    }

    public function test_returns_related_links_with_reasons(): void
    {
        $horizon = $this->other('Laravel Horizon');
        $this->other('Cooking pasta');
        $this->pick([['id' => $horizon->id, 'similarity_reason' => 'Both are about queue workers.']]);

        $this->related()->assertOk()->assertExactJson(['data' => [
            'source_link_id' => $this->source->id,
            'related_links' => [[
                'id' => $horizon->id, 'title' => 'Laravel Horizon', 'url' => 'https://example.com/laravel-horizon',
                'similarity_reason' => 'Both are about queue workers.',
            ]],
        ]]);
    }

    public function test_the_prompt_has_the_source_and_candidates_but_not_the_source_itself_as_candidate(): void
    {
        $horizon = $this->other('Laravel Horizon', ['note' => 'dashboard notes']);
        $horizon->forceFill(['ai_summary' => '- about supervisor'])->save();
        $this->pick([]);

        $this->related()->assertOk()->assertJsonPath('data.related_links', []);

        Http::assertSent(function (Request $r) use ($horizon) {
            $prompt = FakeGemini::prompt($r);
            [$source, $candidates] = explode('CANDIDATES DATA START', $prompt, 2);

            return str_contains($source, 'Laravel queues') && str_contains($candidates, $horizon->id)
                && str_contains($candidates, 'dashboard notes') && str_contains($candidates, '- about supervisor')
                && ! str_contains($candidates, $this->source->id)
                && ! str_contains(json_encode($r->data()), 'SECRET123')
                && data_get($r->data(), 'generationConfig.responseSchema.required') === ['related'];
        });
    }

    public function test_made_up_foreign_self_and_duplicate_ids_are_dropped(): void
    {
        $a = $this->other('Alpha');
        $b = $this->other('Beta');
        $foreign = $this->other('Their secret', [], User::factory()->create());
        $this->pick([
            ['id' => $foreign->id, 'similarity_reason' => 'leak'],
            ['id' => $this->source->id, 'similarity_reason' => 'itself'],
            ['id' => '01jc3k9m4x7v2b8n5q6r0s1t2w', 'similarity_reason' => 'made up'],
            ['id' => strtoupper($a->id), 'similarity_reason' => 'first'],
            ['id' => $a->id, 'similarity_reason' => 'duplicate'],
            ['id' => "  {$b->id} ", 'similarity_reason' => 'second'],
            ['id' => 5, 'similarity_reason' => 'bad'], 'text', null, ['similarity_reason' => 'no id'],
        ]);

        $response = $this->related()->assertOk();

        $this->assertSame([$a->id, $b->id], array_column($response->json('data.related_links'), 'id'));
        $this->assertSame(['first', 'second'], array_column($response->json('data.related_links'), 'similarity_reason'));
        $this->assertStringNotContainsString('leak', $response->getContent());
    }

    public function test_other_users_links_are_never_sent(): void
    {
        $this->other('Mine');
        $this->other('Their secret plan', ['note' => 'confidential'], User::factory()->create());
        $this->pick([]);
        $this->related()->assertOk();

        Http::assertSent(fn (Request $r) => ! str_contains(json_encode($r->data()), 'Their secret plan') && ! str_contains(json_encode($r->data()), 'confidential'));
    }

    public function test_at_most_five_are_returned_and_only_thirty_candidates_are_sent(): void
    {
        $ids = [];
        for ($i = 1; $i <= 34; $i++) {
            $ids[$i] = $this->other("Item $i")->id;
            Link::query()->whereKey($ids[$i])->update(['created_at' => now()->subHours(100 - $i)]);
        }
        $this->pick(array_map(fn ($i) => ['id' => $ids[$i], 'similarity_reason' => "r$i"], range(34, 20)));

        $this->related()->assertOk()->assertJsonCount(5, 'data.related_links');

        Http::assertSent(function (Request $r) use ($ids) {
            $prompt = FakeGemini::prompt($r);

            return str_contains($prompt, $ids[34]) && str_contains($prompt, $ids[5]) && ! str_contains($prompt, $ids[4]) && ! str_contains($prompt, $ids[1]);
        });
    }

    public function test_a_user_with_no_other_links_gets_an_empty_list_without_a_provider_call(): void
    {
        Http::fake();

        $this->related()->assertOk()->assertExactJson(['data' => ['source_link_id' => $this->source->id, 'related_links' => []]]);

        Http::assertNothingSent();
    }

    public function test_results_are_not_cached(): void
    {
        $a = $this->other('Alpha');
        $this->pick([['id' => $a->id, 'similarity_reason' => 'one']]);
        $this->related()->assertOk();
        $this->resetAuth();
        $this->related()->assertOk();

        Http::assertSentCount(2);
    }

    public function test_unusable_output_is_a_502(): void
    {
        $this->other('Alpha');

        foreach ([fn () => FakeGemini::reply(['related' => 'none']), fn () => FakeGemini::reply(['x' => 1]), fn () => FakeGemini::replyText('Try Alpha!')] as $arrange) {
            $this->freshHttp();
            $arrange();
            $this->resetAuth();
            $this->assertApiError($this->related(), 502, 'AI_INVALID_RESPONSE');
        }
    }

    public function test_ownership_authentication_and_ids(): void
    {
        $theirs = $this->other('Theirs', [], User::factory()->create());
        $this->other('Alpha');
        $this->pick([]);

        $this->assertApiError($this->related($theirs), 404, 'NOT_FOUND');
        $this->assertApiError($this->withToken($this->token)->getJson('/api/v1/links/01jc3k9m4x7v2b8n5q6r0s1t2w/related'), 404, 'NOT_FOUND');
        $this->assertApiError($this->withToken($this->token)->getJson('/api/v1/links/nope/related'), 404, 'NOT_FOUND');
        $this->resetAuth();
        $this->flushHeaders();
        $this->assertApiError($this->getJson('/api/v1/links/'.$this->source->id.'/related'), 401, 'UNAUTHENTICATED');
        Http::assertNothingSent();
    }

    public function test_it_is_a_get_only_route(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/links/'.$this->source->id.'/related')->assertStatus(405);
    }

    public function test_a_missing_key_is_a_503_even_without_other_links(): void
    {
        FakeGemini::configure(null);
        Http::fake();

        $this->assertApiError($this->related(), 503, 'AI_SERVICE_UNAVAILABLE');
        Http::assertNothingSent();
    }

    public function test_timeouts_and_provider_errors(): void
    {
        $this->other('Alpha');
        FakeGemini::timeout();
        $this->assertApiError($this->related(), 504, 'AI_TIMEOUT');

        foreach ([403, 429, 500] as $status) {
            $this->freshHttp();
            FakeGemini::status($status, ['error' => ['message' => FakeGemini::KEY]]);
            $this->resetAuth();
            $response = $this->assertApiError($this->related(), 503, 'AI_SERVICE_UNAVAILABLE');
            $this->assertStringNotContainsString(FakeGemini::KEY, $response->getContent());
        }
    }

    public function test_a_source_link_with_nothing_to_compare_is_a_422(): void
    {
        $bare = $this->saveLink($this->user, 'https://example.com/bare');
        $this->other('Alpha');
        Http::fake();

        $this->assertApiError($this->related($bare), 422, 'VALIDATION_FAILED');
        Http::assertNothingSent();
    }

    public function test_logs_hold_no_key_url_or_content(): void
    {
        $this->other('Private diary');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });

        foreach ([fn () => FakeGemini::timeout(), fn () => FakeGemini::status(403, ['error' => ['message' => FakeGemini::KEY]])] as $arrange) {
            $this->freshHttp();
            $arrange();
            $this->resetAuth();
            $this->related();
        }

        $all = implode("\n", $logged);
        $this->assertStringContainsString('AI request failed.', $all);
        foreach ([FakeGemini::KEY, $this->token, 'SECRET123', 'Laravel queues', 'Private diary'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_it_shares_the_ai_rate_limit(): void
    {
        $this->other('Alpha');
        $this->pick([]);

        for ($i = 0; $i < 10; $i++) {
            $this->related()->assertOk();
        }

        $this->assertApiError($this->related(), 429, 'RATE_LIMITED')->assertHeader('Retry-After');
    }
}
