<?php

namespace App\Services\Ai;

use App\Exceptions\ApiException;
use App\Support\Api\ApiError;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The AI provider cannot be used or returned something unusable. The reason is a
 * short code for logs and tests; it never contains the API key, a prompt or a link.
 */
final class AiException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const TIMEOUT = 'timeout';

    public const UNAVAILABLE = 'unavailable';

    public const INVALID_RESPONSE = 'invalid_response';

    public function __construct(public readonly string $reason, public readonly ?int $httpStatus = null)
    {
        parent::__construct('AI request failed: '.$reason);
    }

    public function toApiException(): ApiException
    {
        Log::warning('AI request failed.', ['reason' => $this->reason, 'status' => $this->httpStatus]);

        return match ($this->reason) {
            self::NOT_CONFIGURED => new ApiException(ApiError::AI_SERVICE_UNAVAILABLE, 503, 'The AI assistant is not configured on this server.'),
            self::TIMEOUT => new ApiException(ApiError::AI_TIMEOUT, 504, 'The AI service took too long to answer. Please try again.'),
            self::INVALID_RESPONSE => new ApiException(ApiError::AI_INVALID_RESPONSE, 502, 'The AI service returned an answer that could not be used. Please try again.'),
            default => new ApiException(ApiError::AI_SERVICE_UNAVAILABLE, 503, 'The AI service is temporarily unavailable. Please try again later.'),
        };
    }
}
