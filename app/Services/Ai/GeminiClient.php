<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal synchronous client for the Gemini REST API (DEC-038): one request per call, no
 * queue, no retry. The key travels in the x-goog-api-key header, never in the URL, and is
 * never logged.
 */
class GeminiClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.gemini.api_key')) && $this->model() !== null;
    }

    /**
     * Ask for a JSON answer matching $schema.
     *
     * @param  array<string, mixed>  $schema  Gemini responseSchema (OpenAPI subset)
     * @return array<string, mixed>
     *
     * @throws AiException
     */
    public function generateJson(string $system, string $prompt, array $schema): array
    {
        if (! $this->isConfigured()) {
            throw new AiException(AiException::NOT_CONFIGURED);
        }

        $timeout = max(1, (int) config('services.gemini.timeout'));

        try {
            $response = Http::baseUrl(rtrim((string) config('services.gemini.base_url'), '/'))
                ->withHeaders(['x-goog-api-key' => (string) config('services.gemini.api_key')])
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->connectTimeout(min(5, $timeout))
                ->post('models/'.$this->model().':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature' => 0.3,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $schema,
                    ],
                ]);
        } catch (ConnectionException $e) {
            // The message can carry the request URL; only its kind is kept.
            $timedOut = str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;

            throw new AiException($timedOut ? AiException::TIMEOUT : AiException::UNAVAILABLE);
        }

        if (! $response->successful()) {
            // 400/401/403 (bad key or model), 429 (free-tier quota) and 5xx all look the same to the app.
            throw new AiException(AiException::UNAVAILABLE, $response->status());
        }

        $json = $response->json();
        $text = is_array($json) ? data_get($json, 'candidates.0.content.parts.0.text') : null;

        if (! is_string($text) || trim($text) === '') {
            // Blocked prompt, safety stop or empty candidate list.
            throw new AiException(AiException::INVALID_RESPONSE);
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new AiException(AiException::INVALID_RESPONSE);
        }

        return $decoded;
    }

    private function model(): ?string
    {
        $model = (string) config('services.gemini.model');

        // The model name becomes part of the request path.
        return preg_match('/^[A-Za-z0-9._-]{1,100}$/', $model) === 1 ? $model : null;
    }
}
