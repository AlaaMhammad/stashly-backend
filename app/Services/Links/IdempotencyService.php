<?php

namespace App\Services\Links;

use App\Models\IdempotencyRecord;
use App\Models\User;
use App\Support\Api\ApiError;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Idempotency-Key handling for POST /links (contract 1.1; provisional rules DEC-005):
 * - the key must be a UUID and is scoped to the authenticated user;
 * - a repeat within 24 hours returns the first stored response unchanged;
 * - the same key with a different request body is rejected (422) without processing;
 * - only definitive outcomes (201 created, 409 duplicate) are stored, so a
 *   request that failed validation or errored can be retried with the same key;
 * - concurrent requests with one key are serialized by a lock, so only one is processed.
 */
class IdempotencyService
{
    private const STORED_STATUSES = [201, 409];

    /**
     * @param  array<mixed>  $payload
     * @param  Closure(): JsonResponse  $process
     */
    public function run(User $user, ?string $key, array $payload, Closure $process): JsonResponse
    {
        if ($key === null) {
            return $process();
        }

        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'The Idempotency-Key header must be a UUID.',
            ]);
        }

        $key = strtolower($key);
        $fingerprint = hash('sha256', json_encode(self::canonical($payload)));
        $lock = Cache::lock('idempotency:'.$user->getKey().':'.$key, 30);

        try {
            return $lock->block(
                config('stashly.idempotency.lock_wait_seconds'),
                fn () => $this->runLocked($user, $key, $fingerprint, $process),
            );
        } catch (LockTimeoutException) {
            return ApiError::response(
                ApiError::SERVICE_UNAVAILABLE,
                'A request with this Idempotency-Key is still being processed.',
                503,
                [],
                ['Retry-After' => '1'],
            );
        }
    }

    private function runLocked(User $user, string $key, string $fingerprint, Closure $process): JsonResponse
    {
        $record = IdempotencyRecord::query()
            ->where('user_id', $user->getKey())
            ->where('key', $key)
            ->where('expires_at', '>', now())
            ->first();

        if ($record !== null) {
            if (! hash_equals($record->request_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'This Idempotency-Key was already used with a different request.',
                ]);
            }

            return JsonResponse::fromJsonString($record->response_body, $record->response_status);
        }

        $response = $process();

        if (in_array($response->getStatusCode(), self::STORED_STATUSES, true)) {
            // updateOrCreate also replaces an expired record that still holds this key.
            IdempotencyRecord::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'key' => $key],
                [
                    'request_fingerprint' => $fingerprint,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                    'expires_at' => now()->addHours(config('stashly.idempotency.ttl_hours')),
                ],
            );
        }

        return $response;
    }

    /**
     * Key-order-independent representation of the request body.
     */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
