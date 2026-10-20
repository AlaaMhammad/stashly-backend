<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probes the dependencies GET /health reports on: database, cache, queue.
 */
class HealthCheck
{
    public const OK = 'ok';

    public const FAIL = 'fail';

    /**
     * @return array{database: string, cache: string, queue: string}
     */
    public function run(): array
    {
        return [
            'database' => $this->probe('database', fn () => DB::connection()->select('select 1')),
            'cache' => $this->probe('cache', function () {
                $key = 'health:'.Str::random(16);
                Cache::put($key, self::OK, 10);
                $stored = Cache::get($key);
                Cache::forget($key);

                return $stored === self::OK;
            }),
            'queue' => $this->probe('queue', fn () => Queue::connection()->size()),
        ];
    }

    public function passes(array $checks): bool
    {
        return ! in_array(self::FAIL, $checks, true);
    }

    private function probe(string $name, callable $check): string
    {
        try {
            return $check() === false ? self::FAIL : self::OK;
        } catch (Throwable $e) {
            // Exception class only: driver messages can contain hosts or usernames.
            Log::warning('Health check failed.', ['check' => $name, 'exception' => $e::class]);

            return self::FAIL;
        }
    }
}
