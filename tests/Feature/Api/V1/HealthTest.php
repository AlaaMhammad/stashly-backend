<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * API-SYS-001 GET /health.
 */
class HealthTest extends TestCase
{
    private const ISO_UTC = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    public function test_healthy_dependencies_return_200_with_contract_shape(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertExactJsonStructure(['data' => ['status', 'time', 'checks' => ['database', 'cache', 'queue']]])
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks', ['database' => 'ok', 'cache' => 'ok', 'queue' => 'ok']);

        $this->assertMatchesRegularExpression(self::ISO_UTC, $response->json('data.time'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_is_public(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
    }

    public function test_database_failure_returns_503(): void
    {
        config(['database.connections.sqlite.database' => storage_path('framework/testing/missing/none.sqlite')]);
        DB::purge('sqlite');

        $this->assertFailedCheck('database');
    }

    public function test_cache_failure_returns_503(): void
    {
        // The database cache store needs a "cache" table, which does not exist here.
        config(['cache.default' => 'database']);

        $this->assertFailedCheck('cache');
    }

    public function test_queue_failure_returns_503(): void
    {
        // The database queue needs a "jobs" table, which does not exist here.
        config(['queue.default' => 'database']);

        $this->assertFailedCheck('queue');
    }

    public function test_is_not_rate_limited_so_monitoring_always_gets_a_real_status(): void
    {
        config(['stashly.rate_limits.api_per_minute' => 2]);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/health')->assertOk();
        }
    }

    private function assertFailedCheck(string $failing): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertApiError($response, 503, 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('error.details.status', 'fail')
            ->assertJsonPath("error.details.checks.$failing", 'fail');

        foreach (array_diff(['database', 'cache', 'queue'], [$failing]) as $healthy) {
            $response->assertJsonPath("error.details.checks.$healthy", 'ok');
        }

        $this->assertMatchesRegularExpression(self::ISO_UTC, $response->json('error.details.time'));
    }
}
