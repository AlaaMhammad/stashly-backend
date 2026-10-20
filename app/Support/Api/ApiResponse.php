<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

/**
 * Success envelope: { "data": ... }.
 */
final class ApiResponse
{
    public static function data(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status, $headers);
    }
}
