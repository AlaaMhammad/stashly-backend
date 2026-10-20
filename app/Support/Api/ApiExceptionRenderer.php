<?php

namespace App\Support\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every exception raised under /api/* as the contract error envelope.
 *
 * Messages are fixed per code so internal details (model names, SQL, exception
 * text) never reach the client. Laravel has already converted model-not-found
 * and authorization exceptions into HTTP exceptions before this runs.
 */
final class ApiExceptionRenderer
{
    private const MESSAGES = [
        ApiError::UNAUTHENTICATED => 'Unauthenticated.',
        ApiError::FORBIDDEN => 'This action is not allowed.',
        ApiError::NOT_FOUND => 'Resource not found.',
        ApiError::RATE_LIMITED => 'Too many requests.',
        ApiError::VALIDATION_FAILED => 'The given data was invalid.',
        ApiError::SERVICE_UNAVAILABLE => 'Service temporarily unavailable.',
        ApiError::SERVER_ERROR => 'Something went wrong. Please try again later.',
    ];

    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') || $e instanceof HttpResponseException) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return ApiError::response(
                ApiError::VALIDATION_FAILED,
                self::MESSAGES[ApiError::VALIDATION_FAILED],
                $e->status,
                $e->errors(),
            );
        }

        if ($e instanceof AuthenticationException) {
            return $this->error(ApiError::UNAUTHENTICATED, 401);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return $this->error(self::codeForStatus($status), $status, $e->getHeaders());
        }

        return $this->error(ApiError::SERVER_ERROR, 500);
    }

    /**
     * Status-to-code mapping for HTTP exceptions without a domain-specific code.
     * See DEC-018 for the provisional choices (405 and unmapped 4xx).
     */
    public static function codeForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => ApiError::UNAUTHENTICATED,
            $status === 403 => ApiError::FORBIDDEN,
            $status === 404, $status === 405 => ApiError::NOT_FOUND,
            $status === 429 => ApiError::RATE_LIMITED,
            $status === 503 => ApiError::SERVICE_UNAVAILABLE,
            $status >= 500 => ApiError::SERVER_ERROR,
            default => ApiError::VALIDATION_FAILED,
        };
    }

    private function error(string $code, int $status, array $headers = []): JsonResponse
    {
        return ApiError::response($code, self::MESSAGES[$code], $status, [], $headers);
    }
}
