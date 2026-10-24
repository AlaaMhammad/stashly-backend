<?php

namespace Tests\Feature\Security;

use App\Services\Metadata\CurlTransport;
use App\Services\Metadata\FetchFailure;
use App\Services\Metadata\Target;
use PHPUnit\Framework\TestCase;

/**
 * The real curl transport against a throw-away local PHP server (127.0.0.1
 * only, no internet). Proves what fakes cannot: IP pinning with the hostname
 * kept for Host/SNI, the byte cap (also for compressed bodies), early abort
 * for non-HTML, timeouts, and that redirects are not followed.
 */
class CurlTransportTest extends TestCase
{
    private static $process = null;

    private static int $port = 0;

    private static string $router = '';

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(stream_socket_get_name($socket, false), strrpos(stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        self::$router = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stashly-router-'.getmypid().'.php';
        file_put_contents(self::$router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($path) {
    case '/html':
        header('Content-Type: text/html; charset=utf-8');
        header('Set-Cookie: session=abc; Path=/');
        echo '<html><title>hi</title><!--'.json_encode(array_change_key_case(getallheaders())).'--></html>';
        break;
    case '/big':
        header('Content-Type: text/html');
        for ($i = 0; $i < 64; $i++) { echo str_repeat('a', 32768); flush(); }
        break;
    case '/gzip':
        header('Content-Type: text/html');
        header('Content-Encoding: gzip');
        echo gzencode(str_repeat('a', 3 * 1024 * 1024));
        break;
    case '/json':
        header('Content-Type: application/json');
        echo str_repeat('x', 1000);
        break;
    case '/redirect':
        header('Location: http://127.0.0.1:1/never', true, 302);
        break;
    case '/error':
        http_response_code(503);
        header('Content-Type: text/html');
        echo '<html>down</html>';
        break;
    case '/method':
        header('Content-Type: application/json');
        header('X-Seen-Method: '.$_SERVER['REQUEST_METHOD']);
        header('X-Seen-Accept: '.($_SERVER['HTTP_ACCEPT'] ?? ''));
        echo '{"body":"should never be read for HEAD"}';
        break;
    case '/gone':
        http_response_code(404);
        break;
    case '/slow':
        sleep(2);
        header('Content-Type: text/html');
        echo 'late';
        break;
}
PHP);

        // Array form starts php directly (no shell wrapper), so terminating it really stops the server.
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, self::$router],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
        );

        for ($i = 0; $i < 60; $i++) {
            if (@fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1)) {
                return;
            }
            usleep(100_000);
        }
        self::fail('Local test server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        @unlink(self::$router);
    }

    private function target(string $path, string $host = '127.0.0.1'): Target
    {
        return new Target("http://$host:".self::$port.$path, $host, self::$port, '127.0.0.1');
    }

    private function request(string $path, string $host = '127.0.0.1', float $timeout = 5.0, int $max = 524288)
    {
        return (new CurlTransport('StashlyTest/1.0'))->request($this->target($path, $host), $timeout, $max);
    }

    public function test_connection_is_pinned_to_the_given_ip_while_the_hostname_is_kept(): void
    {
        // pinned.invalid cannot be resolved by DNS: only the pin makes this work.
        $response = $this->request('/html', 'pinned.invalid');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('<title>hi</title>', $response->body);
        preg_match('/<!--(.*)-->/s', $response->body, $m);
        $seen = json_decode($m[1], true);
        $this->assertSame('pinned.invalid:'.self::$port, $seen['host'], 'The Host header carries the hostname, not the IP.');
    }

    public function test_requests_carry_no_cookies_or_credentials_and_a_fixed_user_agent(): void
    {
        $first = $this->request('/html');
        $second = $this->request('/html');

        foreach ([$first, $second] as $response) {
            preg_match('/<!--(.*)-->/s', $response->body, $m);
            $seen = json_decode($m[1], true);
            $this->assertArrayNotHasKey('cookie', $seen);
            $this->assertArrayNotHasKey('authorization', $seen);
            $this->assertSame('StashlyTest/1.0', $seen['user-agent']);
            $this->assertStringContainsString('text/html', $seen['accept']);
        }
    }

    public function test_a_head_request_reads_the_status_and_headers_but_no_body(): void
    {
        $transport = new CurlTransport('StashlyTest/1.0');

        $head = $transport->request($this->target('/method'), 5.0, 1024, 'HEAD');
        $this->assertSame(200, $head->status);
        $this->assertSame('HEAD', $head->headers['x-seen-method']);
        $this->assertSame('*/*', $head->headers['x-seen-accept'], 'A health check accepts any content type.');
        $this->assertSame('', $head->body);

        $this->assertSame(404, $transport->request($this->target('/gone'), 5.0, 1024, 'HEAD')->status);
        $this->assertSame(302, $transport->request($this->target('/redirect'), 5.0, 1024, 'HEAD')->status, 'Redirects are returned, not followed, for HEAD too.');
    }

    public function test_the_default_method_is_still_get(): void
    {
        $get = $this->request('/method');

        $this->assertSame('GET', $get->headers['x-seen-method']);
    }

    public function test_redirects_are_returned_not_followed(): void
    {
        $response = $this->request('/redirect');

        $this->assertSame(302, $response->status);
        $this->assertSame('http://127.0.0.1:1/never', $response->headers['location']);
        $this->assertSame('', $response->body);
    }

    public function test_body_is_cut_at_the_byte_limit(): void
    {
        $response = $this->request('/big', max: 100_000);

        $this->assertTrue($response->truncated);
        $this->assertSame(100_000, strlen($response->body));
    }

    public function test_a_body_within_the_limit_is_complete_and_not_truncated(): void
    {
        $response = $this->request('/html');

        $this->assertFalse($response->truncated);
        $this->assertStringEndsWith('</html>', $response->body);
    }

    public function test_the_limit_applies_to_decompressed_bytes(): void
    {
        $response = $this->request('/gzip', max: 200_000);

        $this->assertTrue($response->truncated);
        $this->assertSame(200_000, strlen($response->body));
    }

    public function test_non_html_bodies_are_not_read(): void
    {
        $response = $this->request('/json');

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json', $response->contentType());
        $this->assertFalse($response->isHtml());
        $this->assertSame('', $response->body);
    }

    public function test_error_responses_are_reported_without_a_body(): void
    {
        $response = $this->request('/error');

        $this->assertSame(503, $response->status);
        $this->assertSame('', $response->body);
    }

    public function test_connection_failure_is_reported_as_unreachable(): void
    {
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($closed, false);
        fclose($closed);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        try {
            (new CurlTransport)->request(new Target("http://127.0.0.1:$port/", '127.0.0.1', $port, '127.0.0.1'), 10.0, 1000);
            $this->fail('Expected a failure.');
        } catch (FetchFailure $failure) {
            $this->assertSame(FetchFailure::UNREACHABLE, $failure->errorCode);
            $this->assertFalse($failure->temporary);
        }
    }

    public function test_a_failed_tls_handshake_is_reported_as_a_tls_error(): void
    {
        // HTTPS to a plain-HTTP server: the handshake cannot succeed.
        try {
            (new CurlTransport)->request(new Target('https://127.0.0.1:'.self::$port.'/', '127.0.0.1', self::$port, '127.0.0.1'), 5.0, 1000);
            $this->fail('Expected a failure.');
        } catch (FetchFailure $failure) {
            $this->assertSame(FetchFailure::TLS_ERROR, $failure->errorCode);
            $this->assertFalse($failure->temporary);
        }
    }

    public function test_a_slow_server_times_out_within_the_budget(): void
    {
        $started = microtime(true);

        try {
            $this->request('/slow', timeout: 0.5);
            $this->fail('Expected a timeout.');
        } catch (FetchFailure $failure) {
            $this->assertSame(FetchFailure::TIMEOUT, $failure->errorCode);
            $this->assertTrue($failure->temporary);
        }

        $this->assertLessThan(1.5, microtime(true) - $started);
    }
}
