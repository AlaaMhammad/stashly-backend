<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * php artisan stashly:preflight: deployment smoke check of configuration.
 */
class PreflightTest extends TestCase
{
    private function run_(): array
    {
        $code = Artisan::call('stashly:preflight');

        return [$code, Artisan::output()];
    }

    private function productionConfig(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://api.stashly.example',
            'database.default' => 'mysql', 'database.connections.mysql.username' => 'stashly_app',
            'database.connections.mysql.password' => 'sentinel-db-password',
            'queue.default' => 'database', 'cache.default' => 'database', 'mail.default' => 'smtp',
            'stashly.terms.url' => 'https://x/terms', 'stashly.privacy.url' => 'https://x/privacy',
            'stashly.terms.version' => '1', 'stashly.app_versions.min_supported' => '1.0.0', 'stashly.app_versions.latest' => '1.0.0',
        ]);
    }

    public function test_the_default_test_environment_fails_the_production_checks(): void
    {
        [$code, $output] = $this->run_();

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/FAIL\s+\|?\s*APP_ENV is production/', $output);
        $this->assertStringContainsString('failure(s)', $output);
    }

    public function test_a_correct_production_configuration_passes_everything_it_can_check_offline(): void
    {
        $this->productionConfig();

        [, $output] = $this->run_();

        foreach (['APP_ENV is production', 'APP_DEBUG is false', 'APP_URL uses https', 'APP_KEY is set', 'Database is MySQL/MariaDB',
            'Database user is set', 'Queue is asynchronous', 'Cache is shared', 'Mail sends'] as $check) {
            $this->assertMatchesRegularExpression('/OK\s+\|?\s*'.preg_quote($check, '/').'/', $output, $check);
        }
        // No database server with these credentials exists in the test run.
        $this->assertMatchesRegularExpression('/FAIL\s+\|?\s*Database is reachable/', $output);
    }

    public function test_each_dangerous_setting_is_reported_as_a_failure(): void
    {
        foreach ([
            ['app.debug', true, 'APP_DEBUG is false'],
            ['app.url', 'http://api.stashly.example', 'APP_URL uses https'],
            ['app.key', '', 'APP_KEY is set'],
            ['queue.default', 'sync', 'Queue is asynchronous'],
            ['mail.default', 'log', 'Mail sends'],
            ['cache.default', 'array', 'Cache is shared'],
        ] as [$key, $value, $check]) {
            $this->productionConfig();
            config([$key => $value]);

            [$code, $output] = $this->run_();

            $this->assertSame(1, $code);
            $this->assertMatchesRegularExpression('/FAIL\s+\|?\s*'.preg_quote($check, '/').'/', $output, $check);
        }
    }

    public function test_a_missing_google_client_id_is_a_warning_not_a_failure(): void
    {
        $this->productionConfig();
        config(['services.google.client_id' => null]);
        [, $output] = $this->run_();
        $this->assertMatchesRegularExpression('/WARN\s+\|?\s*Google sign-in client id/', $output);

        config(['services.google.client_id' => 'secret-looking-client-id.apps.googleusercontent.com']);
        [, $output] = $this->run_();
        $this->assertMatchesRegularExpression('/OK\s+\|?\s*Google sign-in client id/', $output);
        $this->assertStringNotContainsString('secret-looking-client-id', $output);
    }

    public function test_warnings_do_not_fail_the_check_and_secrets_are_never_printed(): void
    {
        $this->productionConfig();
        config(['database.connections.mysql.username' => 'root', 'stashly.terms.url' => null]);

        [, $output] = $this->run_();

        $this->assertMatchesRegularExpression('/WARN\s+\|?\s*Database user is not root/', $output);
        $this->assertMatchesRegularExpression('/WARN\s+\|?\s*Terms\/Privacy URLs/', $output);
        $this->assertStringNotContainsString('sentinel-db-password', $output);
        $this->assertStringNotContainsString(config('app.key'), $output);
    }
}
