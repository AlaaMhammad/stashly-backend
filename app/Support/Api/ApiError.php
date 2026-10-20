<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use stdClass;

/**
 * Error envelope: { "error": { "code", "message", "details", "request_id" } }.
 * Codes are the contract's stable error codes; clients branch on code, never message.
 */
final class ApiError
{
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const INVALID_URL = 'INVALID_URL';

    public const INVALID_CURSOR = 'INVALID_CURSOR';

    public const LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';

    public const TOKEN_INVALID_OR_EXPIRED = 'TOKEN_INVALID_OR_EXPIRED';

    public const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';

    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const NOT_FOUND = 'NOT_FOUND';

    public const DUPLICATE_LINK = 'DUPLICATE_LINK';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const SERVER_ERROR = 'SERVER_ERROR';

    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function response(
        string $code,
        string $message,
        int $status,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                // Always a JSON object, never an empty array.
                'details' => $details === [] ? new stdClass : $details,
                'request_id' => RequestId::current(),
            ],
        ], $status, $headers);
    }
}
