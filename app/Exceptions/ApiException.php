<?php

namespace App\Exceptions;

use App\Support\Api\ApiError;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An expected API failure with a contract error code (e.g. INVALID_URL).
 * Rendered as the error envelope and never reported as an application error.
 */
class ApiException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidUrl(): self
    {
        return new self(ApiError::INVALID_URL, 422, 'This URL cannot be saved.');
    }

    public static function invalidCursor(): self
    {
        return new self(ApiError::INVALID_CURSOR, 422, 'The cursor is invalid or does not match these filters.');
    }

    public function render(): JsonResponse
    {
        return ApiError::response($this->errorCode, $this->getMessage(), $this->status, $this->details);
    }
}
