<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase as ApplicationTestCase;

/**
 * Isolated tests of the stashly_test safety guard. They use configuration arrays
 * and a throw-away application object: no database is ever connected to.
 */
class TestDatabaseGuardTest extends TestCase
{
    private function assertRefused(array $connection, string $messagePart): void
    {
        try {
            TestDatabaseGuard::assertConnectionIsSafe($connection);
            $this->fail('Expected the guard to refuse this configuration.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());
            $this->assertStringContainsString('Refusing to run tests', $e->getMessage());
            $this->assertStringContainsString('No database was touched', $e->getMessage());
        }
    }

    #[DataProvider('accepted')]
    public function test_accepts_in_memory_sqlite_and_the_dedicated_database(array $connection): void
    {
        TestDatabaseGuard::assertConnectionIsSafe($connection);

        $this->addToAssertionCount(1);
    }

    public static function accepted(): array
    {
        return [
            'in-memory sqlite (default suite)' => [['driver' => 'sqlite', 'database' => ':memory:']],
            'mariadb + stashly_test' => [['driver' => 'mariadb', 'database' => 'stashly_test', 'host' => '127.0.0.1']],
            'mysql + stashly_test' => [['driver' => 'mysql', 'database' => 'stashly_test', 'host' => '127.0.0.1']],
            'empty url is ignored' => [['driver' => 'mysql', 'database' => 'stashly_test', 'url' => '']],
            'url without a path keeps the configured database' => [['driver' => 'mysql', 'database' => 'stashly_test', 'url' => 'mysql://u:p@127.0.0.1:3306']],
            'url that agrees' => [['driver' => 'mysql', 'database' => 'other', 'url' => 'mysql://u:p@127.0.0.1:3306/stashly_test']],
        ];
    }

    #[DataProvider('differentNames')]
    public function test_rejects_a_different_database_name(string $name): void
    {
        $this->assertRefused(['driver' => 'mysql', 'database' => $name], 'must be exactly "stashly_test"');
        $this->assertRefused(['driver' => 'mariadb', 'database' => $name], 'must be exactly "stashly_test"');
    }

    public static function differentNames(): array
    {
        return [
            'the real database' => ['stashly'],
            'laravel default' => ['laravel'],
            'prefix only' => ['stashly_test2'],
            'suffix match only' => ['prod_stashly_test'],
            'wrong case' => ['Stashly_Test'],
            'trailing space' => ['stashly_test '],
            'mysql system schema' => ['mysql'],
            'information_schema' => ['information_schema'],
        ];
    }

    #[DataProvider('missingNames')]
    public function test_rejects_a_missing_database_name(array $connection): void
    {
        $this->assertRefused($connection, 'database name is missing');
    }

    public static function missingNames(): array
    {
        return [
            'null' => [['driver' => 'mysql', 'database' => null]],
            'empty string' => [['driver' => 'mariadb', 'database' => '']],
            'key absent' => [['driver' => 'mysql']],
            'not a string' => [['driver' => 'mysql', 'database' => 123]],
        ];
    }

    #[DataProvider('unexpectedDrivers')]
    public function test_rejects_an_unexpected_driver(array $connection, string $messagePart): void
    {
        $this->assertRefused($connection, $messagePart);
    }

    public static function unexpectedDrivers(): array
    {
        return [
            'pgsql' => [['driver' => 'pgsql', 'database' => 'stashly_test'], 'driver "pgsql" is not allowed'],
            'sqlsrv' => [['driver' => 'sqlsrv', 'database' => 'stashly_test'], 'driver "sqlsrv" is not allowed'],
            'unknown' => [['driver' => 'oracle', 'database' => 'stashly_test'], 'driver "oracle" is not allowed'],
            'wrong case' => [['driver' => 'MySQL', 'database' => 'stashly_test'], 'driver "MySQL" is not allowed'],
            'missing driver' => [['database' => 'stashly_test'], 'driver is missing'],
            'empty driver' => [['driver' => '', 'database' => 'stashly_test'], 'driver is missing'],
        ];
    }

    public function test_sqlite_must_be_in_memory(): void
    {
        foreach (['database/database.sqlite', 'C:/data/stashly.sqlite', '', null, 'stashly_test'] as $database) {
            $this->assertRefused(['driver' => 'sqlite', 'database' => $database], 'in-memory database');
        }
    }

    #[DataProvider('hostileUrls')]
    public function test_a_url_that_overrides_the_database_is_evaluated_not_trusted(array $connection, string $messagePart): void
    {
        // Laravel applies "url" over the other keys when it opens a connection.
        $this->assertRefused($connection, $messagePart);
    }

    public static function hostileUrls(): array
    {
        return [
            'url points at the real database' => [
                ['driver' => 'mysql', 'database' => 'stashly_test', 'url' => 'mysql://u:p@127.0.0.1:3306/stashly'],
                'must be exactly "stashly_test", got "stashly"',
            ],
            'url switches driver' => [
                ['driver' => 'mysql', 'database' => 'stashly_test', 'url' => 'pgsql://u:p@127.0.0.1/stashly_test'],
                'driver "pgsql" is not allowed',
            ],
        ];
    }

    public function test_the_error_never_contains_credentials(): void
    {
        try {
            TestDatabaseGuard::assertConnectionIsSafe([
                'driver' => 'mysql', 'database' => 'stashly', 'username' => 'secret-user', 'password' => 'secret-pass',
                'url' => 'mysql://url-user:url-pass@db.internal:3306/stashly',
            ]);
            $this->fail('Expected refusal.');
        } catch (RuntimeException $e) {
            foreach (['secret-user', 'secret-pass', 'url-user', 'url-pass', 'db.internal'] as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    public function test_the_application_check_reads_the_effective_default_connection(): void
    {
        $app = $this->application([
            'database.default' => 'main',
            'database.connections.main' => ['driver' => 'mysql', 'database' => 'stashly_test'],
            // A different, unsafe connection that is not the default must not matter here.
            'database.connections.other' => ['driver' => 'mysql', 'database' => 'stashly'],
        ]);
        TestDatabaseGuard::assertApplicationIsSafe($app);
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('got "stashly"');
        TestDatabaseGuard::assertApplicationIsSafe($this->application([
            'database.default' => 'other',
            'database.connections.other' => ['driver' => 'mysql', 'database' => 'stashly'],
        ]));
    }

    #[DataProvider('brokenApplications')]
    public function test_the_application_check_refuses_a_missing_default_or_connection(array $values, string $messagePart): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($messagePart);

        TestDatabaseGuard::assertApplicationIsSafe($this->application($values));
    }

    public static function brokenApplications(): array
    {
        return [
            'no default' => [['database.default' => null], 'no default database connection'],
            'empty default' => [['database.default' => ''], 'no default database connection'],
            'undefined connection' => [['database.default' => 'ghost', 'database.connections' => []], 'not defined'],
        ];
    }

    /**
     * The guard is wired into the real test bootstrap: creating the application for a
     * test with an unsafe effective configuration fails before any trait (and so
     * RefreshDatabase) can run. No connection is opened.
     */
    public function test_the_test_bootstrap_runs_the_guard_before_the_application_is_handed_to_a_test(): void
    {
        $probe = new class('probe') extends ApplicationTestCase {};
        $keys = ['DB_CONNECTION', 'DB_DATABASE', 'DB_URL'];
        $original = array_map(fn ($k) => $_SERVER[$k] ?? $_ENV[$k] ?? getenv($k), array_combine($keys, $keys));

        try {
            $this->setEnvironment(['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'stashly', 'DB_URL' => '']);

            try {
                $probe->createApplication();
                $this->fail('The bootstrap must refuse a non-test database.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('got "stashly"', $e->getMessage());
            }

            $this->setEnvironment(['DB_CONNECTION' => 'mariadb', 'DB_DATABASE' => 'stashly_test', 'DB_URL' => '']);
            $this->assertInstanceOf(Application::class, $probe->createApplication());
        } finally {
            $this->setEnvironment($original);

            // Each booted application registers an error and an exception handler; two were booted.
            for ($i = 0; $i < 2; $i++) {
                restore_error_handler();
                restore_exception_handler();
            }
        }
    }

    /**
     * @param  array<string, string|false|null>  $values
     */
    private function setEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null || $value === false) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);

                continue;
            }
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function application(array $values): Application
    {
        $config = new Repository(['database' => ['default' => null, 'connections' => []]]);
        foreach ($values as $key => $value) {
            $config->set($key, $value);
        }

        $app = new Container;
        $app->instance('config', $config);

        return new class($app) extends \Illuminate\Foundation\Application
        {
            public function __construct(private Container $inner)
            {
                // Intentionally does not boot a framework: only offsetGet('config') is used.
            }

            public function offsetGet($key): mixed
            {
                return $this->inner->make($key);
            }
        };
    }
}
