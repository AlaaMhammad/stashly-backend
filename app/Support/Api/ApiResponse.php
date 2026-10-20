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

    /**
     * List envelope: { "data": [...], "meta": { per_page, has_more, next_cursor, total? } }.
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $meta
     */
    public static function list(array $items, array $meta): JsonResponse
    {
        return new JsonResponse(['data' => $items, 'meta' => $meta]);
    }
}
