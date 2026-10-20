<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Pins the two PHPUnit configurations: the default suite stays on in-memory
 * SQLite, and the MySQL-compatible suite targets only stashly_test with no
 * credentials stored in the repository. Reads XML only; no database is involved.
 */
class PhpunitConfigurationTest extends TestCase
{
    /**
     * @return array<string, array{value: string, force: bool}>
     */
    private function env(string $file): array
    {
        $xml = new SimpleXMLElement(file_get_contents(dirname(__DIR__, 2).'/'.$file));
        $env = [];
        foreach ($xml->php->env as $node) {
            $env[(string) $node['name']] = ['value' => (string) $node['value'], 'force' => (string) $node['force'] === 'true'];
        }

        return $env;
    }

    public function test_the_default_suite_still_uses_in_memory_sqlite(): void
    {
        $env = $this->env('phpunit.xml');

        $this->assertSame('sqlite', $env['DB_CONNECTION']['value']);
        $this->assertSame(':memory:', $env['DB_DATABASE']['value']);
        $this->assertSame('', $env['DB_URL']['value']);
        $this->assertSame('array', $env['CACHE_STORE']['value']);
        $this->assertSame('sync', $env['QUEUE_CONNECTION']['value']);
        $this->assertSame('array', $env['MAIL_MAILER']['value']);
    }

    public function test_the_mysql_suite_targets_only_stashly_test_and_forces_it(): void
    {
        $env = $this->env('phpunit.mysql.xml');

        $this->assertSame('stashly_test', $env['DB_DATABASE']['value']);
        $this->assertTrue($env['DB_DATABASE']['force'], 'A shell or .env DB_DATABASE must not override it.');
        $this->assertSame('', $env['DB_URL']['value']);
        $this->assertTrue($env['DB_URL']['force'], 'A DB_URL must not redirect the connection.');
        $this->assertContains($env['DB_CONNECTION']['value'], ['mariadb', 'mysql']);
    }

    public function test_the_mysql_suite_stores_no_credentials_or_hosts(): void
    {
        $env = $this->env('phpunit.mysql.xml');

        foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET'] as $key) {
            $this->assertArrayNotHasKey($key, $env, "$key must come from the environment, not the file.");
        }
    }

    public function test_the_mysql_suite_keeps_everything_else_identical_to_the_default_suite(): void
    {
        $default = $this->env('phpunit.xml');
        $mysql = $this->env('phpunit.mysql.xml');

        $database = ['DB_CONNECTION', 'DB_DATABASE', 'DB_URL'];
        $this->assertSame(
            array_diff_key($default, array_flip($database)),
            array_diff_key($mysql, array_flip($database)),
            'Cache, queue, mail, session and the other test settings must not differ.',
        );

        $suites = fn (string $file) => array_map('strval', (array) (new SimpleXMLElement(file_get_contents(dirname(__DIR__, 2).'/'.$file)))->testsuites->testsuite->directory);
        $this->assertSame($suites('phpunit.xml'), $suites('phpunit.mysql.xml'));
    }
}
