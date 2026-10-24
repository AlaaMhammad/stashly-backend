<?php

namespace Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the Gemini REST API (DEC-038). No test reaches the network.
 */
final class FakeGemini
{
    public const KEY = 'test-gemini-key-do-not-log';

    public const MODEL = 'gemini-3.5-flash-lite';

    /** Configure a key and model, as an operator would in .env. */
    public static function configure(?string $key = self::KEY): void
    {
        config([
            'services.gemini.api_key' => $key,
            'services.gemini.model' => self::MODEL,
            'services.gemini.timeout' => 20,
        ]);
    }

    /** A Gemini generateContent body whose single candidate carries $text. */
    public static function body(string $text): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => 'STOP']]];
    }

    /** Every request is answered with $payload encoded as the model's JSON text. */
    public static function reply(array $payload): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(self::body(json_encode($payload)))]);
    }

    public static function replyText(string $text): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(self::body($text))]);
    }

    public static function status(int $status, array $json = ['error' => ['message' => 'nope']]): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($json, $status)]);
    }

    public static function timeout(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds')]);
    }

    public static function unreachable(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host')]);
    }

    /** The prompt text of the (single) recorded request. */
    public static function prompt(Request $request): string
    {
        return (string) data_get($request->data(), 'contents.0.parts.0.text');
    }
}
