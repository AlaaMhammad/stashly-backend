<?php

namespace Tests\Feature\AI;

use App\Models\Collection;
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
 * API-AI-006 POST /ai/suggest-collection (DEC-039). Gemini is always faked.
 */
class SuggestCollectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private Collection $dev;

    private Collection $cooking;

    protected function setUp(): void
    {
        parent::setUp();

        FakeGemini::configure();
        $this->user = User::factory()->create();
        $this->token = $this->tokenFor($this->user);
        $this->dev = $this->user->collections()->create(['name' => 'Development']);
        $this->cooking = $this->user->collections()->create(['name' => 'Cooking']);
    }

    private function suggest(array $body = [])
    {
        return $this->withToken($this->token)->postJson('/api/v1/ai/suggest-collection', $body + [
            'title' => 'Laravel queue workers', 'url' => 'https://example.com/queues?token=SECRET123', 'description' => 'How to run workers',
        ]);
    }

    private function pick(?string $id, string $confidence = 'high', string $reason = 'It is about programming.'): void
    {
        FakeGemini::reply(['collection_id' => $id, 'confidence' => $confidence, 'reason' => $reason]);
    }

    private function freshHttp(): void
    {
        Http::swap(new Factory);
    }

    public function test_returns_the_best_fitting_collection(): void
    {
        $this->pick($this->dev->id);

        $this->suggest()->assertOk()->assertExactJson(['data' => [
            'suggested_collection_id' => $this->dev->id,
            'suggested_collection_name' => 'Development',
            'confidence' => 'high',
            'reason' => 'It is about programming.',
        ]]);
    }

    public function test_the_prompt_has_the_link_and_only_the_users_collections_and_no_full_url(): void
    {
        $stranger = User::factory()->create();
        $stranger->collections()->create(['name' => 'Their private stuff']);
        $this->pick($this->dev->id);
        $this->suggest()->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            $prompt = FakeGemini::prompt($r);
            $data = json_encode($r->data());

            return str_contains($prompt, 'Laravel queue workers') && str_contains($prompt, 'How to run workers')
                && str_contains($prompt, 'example.com') && str_contains($prompt, $this->dev->id) && str_contains($prompt, 'Cooking')
                && ! str_contains($data, 'SECRET123') && ! str_contains($data, '/queues') && ! str_contains($data, 'Their private stuff')
                && ! str_contains($data, $this->user->email) && ! str_contains($data, $this->token)
                && data_get($r->data(), 'generationConfig.responseSchema.required') === ['collection_id', 'confidence', 'reason'];
        });
    }

    public function test_a_user_without_collections_gets_a_suggestion_to_create_one_without_calling_gemini(): void
    {
        $this->user->collections()->delete();
        FakeGemini::configure(null);
        Http::fake();

        $this->suggest()->assertOk()->assertExactJson(['data' => [
            'suggested_collection_id' => null,
            'suggested_collection_name' => null,
            'confidence' => 'low',
            'reason' => 'You have no collections yet. Create one to organize this link.',
        ]]);

        Http::assertNothingSent();
    }

    public function test_other_users_collections_do_not_count_as_the_users_own(): void
    {
        $this->user->collections()->delete();
        User::factory()->create()->collections()->create(['name' => 'Elsewhere']);
        Http::fake();

        $this->suggest()->assertOk()->assertJsonPath('data.suggested_collection_id', null);
        Http::assertNothingSent();
    }

    public function test_none_fits_made_up_foreign_and_malformed_ids_become_a_null_suggestion(): void
    {
        $foreign = User::factory()->create()->collections()->create(['name' => 'Foreign']);

        foreach ([null, '01jc3k9m4x7v2b8n5q6r0s1t2w', $foreign->id, 'Development', ''] as $id) {
            $this->freshHttp();
            $this->pick($id, 'high', 'Nothing fits well.');
            $this->resetAuth();

            $this->suggest()->assertOk()->assertExactJson(['data' => [
                'suggested_collection_id' => null, 'suggested_collection_name' => null, 'confidence' => 'low', 'reason' => 'Nothing fits well.',
            ]]);
        }

        $this->freshHttp();
        FakeGemini::reply(['collection_id' => 42, 'confidence' => 'high', 'reason' => 'x']);
        $this->resetAuth();
        $this->suggest()->assertOk()->assertJsonPath('data.suggested_collection_id', null);
    }

    public function test_the_id_is_matched_case_insensitively_and_the_name_comes_from_the_database(): void
    {
        $this->pick('  '.strtoupper($this->cooking->id).' ', 'medium');

        $this->suggest()->assertOk()
            ->assertJsonPath('data.suggested_collection_id', $this->cooking->id)
            ->assertJsonPath('data.suggested_collection_name', 'Cooking')
            ->assertJsonPath('data.confidence', 'medium');
    }

    public function test_an_unknown_confidence_becomes_low_and_the_case_is_normalized(): void
    {
        $this->pick($this->dev->id, 'Very sure');
        $this->suggest()->assertOk()->assertJsonPath('data.confidence', 'low');

        $this->freshHttp();
        $this->pick($this->dev->id, ' HIGH ');
        $this->resetAuth();
        $this->suggest()->assertOk()->assertJsonPath('data.confidence', 'high');
    }

    public function test_an_empty_or_missing_reason_is_a_502(): void
    {
        foreach ([['collection_id' => null, 'confidence' => 'low', 'reason' => ' '], ['collection_id' => null, 'confidence' => 'low'], ['collection_id' => null, 'confidence' => 'low', 'reason' => ['x']]] as $payload) {
            $this->freshHttp();
            FakeGemini::reply($payload);
            $this->resetAuth();
            $this->assertApiError($this->suggest(), 502, 'AI_INVALID_RESPONSE');
        }

        $this->freshHttp();
        FakeGemini::replyText('Development, I think.');
        $this->resetAuth();
        $this->assertApiError($this->suggest(), 502, 'AI_INVALID_RESPONSE');
    }

    public function test_the_payload_is_validated(): void
    {
        config(['stashly.rate_limits.ai_per_minute' => 1000]);
        Http::fake();

        $cases = [
            'missing title' => [['title' => null], 'title'],
            'empty title' => [['title' => ''], 'title'],
            'long title' => [['title' => str_repeat('x', 256)], 'title'],
            'array title' => [['title' => ['x']], 'title'],
            'missing url' => [['url' => null], 'url'],
            'not a url' => [['url' => 'not a url'], 'url'],
            'ftp url' => [['url' => 'ftp://example.com/file'], 'url'],
            'javascript url' => [['url' => 'javascript:alert(1)'], 'url'],
            'long url' => [['url' => 'https://example.com/'.str_repeat('a', 2100)], 'url'],
            'long description' => [['description' => str_repeat('d', 1001)], 'description'],
            'array description' => [['description' => ['x']], 'description'],
        ];

        foreach ($cases as $label => [$override, $field]) {
            $response = $this->suggest($override);
            $this->assertApiError($response, 422, 'VALIDATION_FAILED');
            $this->assertArrayHasKey($field, $response->json('error.details'), $label);
        }

        Http::assertNothingSent();
    }

    public function test_the_boundaries_and_an_omitted_description_are_accepted(): void
    {
        $this->pick($this->dev->id);

        $this->suggest(['title' => str_repeat('t', 255), 'description' => str_repeat('d', 1000)])->assertOk();
        $this->suggest(['description' => null])->assertOk();
        $this->withToken($this->token)->postJson('/api/v1/ai/suggest-collection', ['title' => 'عنوان', 'url' => 'https://example.com/a'])->assertOk();
    }

    public function test_authentication_is_required(): void
    {
        FakeGemini::reply(['collection_id' => null, 'confidence' => 'low', 'reason' => 'x']);

        $this->assertApiError($this->postJson('/api/v1/ai/suggest-collection', ['title' => 't', 'url' => 'https://example.com']), 401, 'UNAUTHENTICATED');
        Http::assertNothingSent();
    }

    public function test_a_missing_key_is_a_503_when_there_are_collections(): void
    {
        FakeGemini::configure(null);
        Http::fake();

        $this->assertApiError($this->suggest(), 503, 'AI_SERVICE_UNAVAILABLE');
        Http::assertNothingSent();
    }

    public function test_timeouts_and_provider_errors(): void
    {
        FakeGemini::timeout();
        $this->assertApiError($this->suggest(), 504, 'AI_TIMEOUT');

        foreach ([403, 429, 500] as $status) {
            $this->freshHttp();
            FakeGemini::status($status, ['error' => ['message' => FakeGemini::KEY]]);
            $this->resetAuth();
            $response = $this->assertApiError($this->suggest(), 503, 'AI_SERVICE_UNAVAILABLE');
            $this->assertStringNotContainsString(FakeGemini::KEY, $response->getContent());
        }
    }

    public function test_logs_hold_no_key_url_or_payload(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });

        foreach ([fn () => FakeGemini::timeout(), fn () => FakeGemini::status(403, ['error' => ['message' => FakeGemini::KEY]])] as $arrange) {
            $this->freshHttp();
            $arrange();
            $this->resetAuth();
            $this->suggest();
        }
        $this->suggest(['title' => 'x', 'url' => 'not a url']);

        $all = implode("\n", $logged);
        $this->assertStringContainsString('AI request failed.', $all);
        foreach ([FakeGemini::KEY, $this->token, 'SECRET123', 'Laravel queue workers', 'How to run workers', 'Development'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_it_shares_the_ai_rate_limit(): void
    {
        $this->pick($this->dev->id);

        for ($i = 0; $i < 10; $i++) {
            $this->suggest()->assertOk();
        }

        $this->assertApiError($this->suggest(), 429, 'RATE_LIMITED')->assertHeader('Retry-After');
    }
}
