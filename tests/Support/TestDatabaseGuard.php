<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

/**
 * Fails closed unless the effective test database is one of exactly two things:
 *
 *  - the default in-memory SQLite database (phpunit.xml), or
 *  - the MySQL-compatible database named exactly "stashly_test" (phpunit.mysql.xml).
 *
 * RefreshDatabase drops every table of the configured database, so any other
 * target (a real "stashly" database, a typo, a missing name, an unexpected
 * driver, or a DB_URL that silently points elsewhere) must stop the run before
 * a single migration happens. It validates the configuration Laravel will
 * actually use, not just an environment variable, and never connects.
 */
final class TestDatabaseGuard
{
    public const DATABASE = 'stashly_test';

    public const MYSQL_DRIVERS = ['mysql', 'mariadb'];

    /**
     * @throws RuntimeException when the effective database is not an allowed test target
     */
    public static function assertApplicationIsSafe(Application $app): void
    {
        $config = $app['config'];
        $name = $config->get('database.default');

        if (! is_string($name) || $name === '') {
            self::refuse('no default database connection is configured');
        }

        $connection = $config->get("database.connections.$name");

        if (! is_array($connection)) {
            self::refuse("the default connection \"$name\" is not defined");
        }

        self::assertConnectionIsSafe($connection);
    }

    /**
     * @param  array<string, mixed>  $connection  a Laravel connection config array
     *
     * @throws RuntimeException
     */
    public static function assertConnectionIsSafe(array $connection): void
    {
        // Laravel applies "url" on top of the other keys when it opens a connection.
        if (is_string($connection['url'] ?? null) && $connection['url'] !== '') {
            $connection = (new ConfigurationUrlParser)->parseConfiguration($connection);
        }

        $driver = $connection['driver'] ?? null;
        $database = $connection['database'] ?? null;

        if (! is_string($driver) || $driver === '') {
            self::refuse('the database driver is missing');
        }

        if ($driver === 'sqlite') {
            if ($database === ':memory:') {
                return;
            }

            self::refuse('SQLite tests must use the in-memory database (":memory:")');
        }

        if (! in_array($driver, self::MYSQL_DRIVERS, true)) {
            self::refuse('the driver "'.$driver.'" is not allowed; expected sqlite (in-memory), mysql or mariadb');
        }

        if (! is_string($database) || $database === '') {
            self::refuse('the database name is missing; expected "'.self::DATABASE.'"');
        }

        if ($database !== self::DATABASE) {
            self::refuse('the database name must be exactly "'.self::DATABASE.'", got "'.$database.'"');
        }
    }

    private static function refuse(string $reason): never
    {
        throw new RuntimeException(
            "Refusing to run tests: $reason. Tests drop and recreate every table in the configured database, "
            .'so only in-memory SQLite or the dedicated "'.self::DATABASE.'" database is allowed. '
            .'No database was touched.'
        );
    }
}
