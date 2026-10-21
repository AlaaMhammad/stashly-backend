<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\System\HealthCheck;
use App\Support\Api\ApiDate;
use App\Support\Api\ApiError;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class SystemController extends Controller
{
    /**
     * API-SYS-001: 200 {status, time, checks{database, cache, queue}}; 503 when a check fails.
     */
    public function health(HealthCheck $healthCheck): JsonResponse
    {
        $checks = $healthCheck->run();
        $passes = $healthCheck->passes($checks);

        $body = [
            'status' => $passes ? HealthCheck::OK : HealthCheck::FAIL,
            'time' => ApiDate::format(now()),
            'checks' => $checks,
        ];
        $headers = ['Cache-Control' => 'no-store'];

        if (! $passes) {
            // Provisional shape (DEC-017): error envelope with the health body in details.
            return ApiError::response(
                ApiError::SERVICE_UNAVAILABLE,
                'Service temporarily unavailable.',
                503,
                $body,
                $headers,
            );
        }

        return ApiResponse::data($body, 200, $headers);
    }

    /**
     * API-SYS-002: 200 {api_version, min_supported_app_version, latest_app_version,
     * terms{version,url}, privacy{url}, limits{...}}. Cacheable 5 min.
     */
    public function config(): JsonResponse
    {
        $config = config('stashly');

        return ApiResponse::data([
            'api_version' => $config['api_version'],
            'min_supported_app_version' => $config['app_versions']['min_supported'],
            'latest_app_version' => $config['app_versions']['latest'],
            'terms' => [
                'version' => $config['terms']['version'],
                // Environment values win; otherwise the draft pages served by this backend (DEC-021).
                'url' => filled($config['terms']['url']) ? $config['terms']['url'] : route('terms'),
            ],
            'privacy' => [
                'url' => filled($config['privacy']['url']) ? $config['privacy']['url'] : route('privacy'),
            ],
            'limits' => $config['limits'],
        ], 200, [
            'Cache-Control' => 'public, max-age='.$config['config_cache_seconds'],
        ]);
    }
}
