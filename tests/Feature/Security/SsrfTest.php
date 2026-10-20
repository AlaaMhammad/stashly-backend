<?php

namespace Tests\Feature\Security;

use App\Services\Metadata\FetchFailure;
use App\Services\Metadata\SafeFetcher;
use App\Services\Metadata\TransportResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeTransport;
use Tests\TestCase;

/**
 * security/ssrf.md checklist (SEC-009..SEC-014) against the real guard and
 * fetcher with fake DNS and a fake transport: no network is involved.
 */
class SsrfTest extends TestCase
{
    private const PAGE = '<html><head><title>Hello</title></head></html>';

    private function fetch(string $url, float $timeout = 8.0)
    {
        return app(SafeFetcher::class)->fetch($url, $timeout);
    }

    private function failureOf(string $url, float $timeout = 8.0): FetchFailure
    {
        try {
            $this->fetch($url, $timeout);
        } catch (FetchFailure $failure) {
            return $failure;
        }

        $this->fail("Expected a FetchFailure for $url");
    }

    private function assertNothingRequested(): void
    {
        $this->assertSame([], $this->transport->requests, 'No request may be sent to a rejected target.');
    }

    #[DataProvider('blockedLiterals')]
    public function test_private_and_special_ip_literals_are_blocked_before_any_request(string $url): void
    {
        $failure = $this->failureOf($url);

        $this->assertSame(FetchFailure::BLOCKED_HOST, $failure->errorCode);
        $this->assertTrue($failure->blocked);
        $this->assertFalse($failure->temporary);
        $this->assertNothingRequested();
    }

    public static function blockedLiterals(): array
    {
        $urls = [
            'http://127.0.0.1/', 'https://127.0.0.1/admin', 'http://[::1]/', 'http://10.0.0.5/', 'http://172.16.0.1/',
            'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[fd00:ec2::254]/latest/meta-data/',
            'http://100.100.100.200/', 'http://0.0.0.0/', 'http://[::ffff:127.0.0.1]/', 'http://[::ffff:169.254.169.254]/',
            'http://[fe80::1]/', 'http://[::]/', 'http://224.0.0.1/', 'http://255.255.255.255/', 'http://[2002:7f00:1::1]/',
        ];

        return array_combine($urls, array_map(fn ($u) => [$u], $urls));
    }

    #[DataProvider('invalidUrls')]
    public function test_unsupported_or_malformed_urls_are_rejected(string $url): void
    {
        $failure = $this->failureOf($url);

        $this->assertSame(FetchFailure::INVALID_URL, $failure->errorCode);
        $this->assertFalse($failure->blocked);
        $this->assertNothingRequested();
        $this->assertSame([], $this->dns->calls, 'DNS must not even be consulted.');
    }

    public static function invalidUrls(): array
    {
        $urls = [
            'file:///etc/passwd', 'data:text/html,hi', 'javascript:alert(1)', 'ftp://example.com/x', 'gopher://example.com/',
            'intent://x#Intent;end', 'https://user:pass@example.com/', 'https://example.com@127.0.0.1/', 'http://localhost/',
            'http://127.1/', 'http://2130706433/', 'http://0x7f.0.0.1/', 'http://0177.0.0.1/', 'http://127.000.000.001/',
            'https://exa mple.com/', 'https://example.com\\@127.0.0.1/', 'https://'.str_repeat('a', 2100).'.com/',
        ];

        return array_combine($urls, array_map(fn ($u) => [$u], $urls));
    }

    #[DataProvider('blockedPorts')]
    public function test_only_ports_80_and_443_are_allowed(string $url): void
    {
        $this->assertSame(FetchFailure::BLOCKED_PORT, $this->failureOf($url)->errorCode);
        $this->assertNothingRequested();
    }

    public static function blockedPorts(): array
    {
        $urls = ['http://example.com:8080/', 'https://example.com:8443/', 'http://example.com:22/', 'http://example.com:6379/',
            'http://example.com:3306/', 'https://example.com:444/', 'http://example.com:81/'];

        return array_combine($urls, array_map(fn ($u) => [$u], $urls));
    }

    public function test_ports_80_and_443_are_fetched(): void
    {
        foreach (['http://example.com/a', 'https://example.com/b', 'http://example.com:80/c', 'https://example.com:443/d', 'http://example.com:443/e'] as $url) {
            $this->transport->on($url === 'http://example.com:80/c' ? 'http://example.com/c' : ($url === 'https://example.com:443/d' ? 'https://example.com/d' : $url), FakeTransport::html(self::PAGE));
            $this->assertSame(self::PAGE, $this->fetch($url)->body);
        }
    }

    public function test_public_hostname_resolving_to_a_private_address_is_blocked(): void
    {
        $this->dns->records['internal.example.com'] = ['10.0.0.8'];
        $this->dns->records['metadata.example.com'] = ['169.254.169.254'];
        $this->dns->records['v6.example.com'] = ['fd00::1'];
        $this->dns->records['mapped.example.com'] = ['::ffff:127.0.0.1'];

        foreach (['internal', 'metadata', 'v6', 'mapped'] as $name) {
            $this->assertSame(FetchFailure::BLOCKED_HOST, $this->failureOf("https://$name.example.com/")->errorCode, $name);
        }
        $this->assertNothingRequested();
    }

    public function test_mixed_public_and_private_records_are_blocked(): void
    {
        $this->dns->records['mixed.example.com'] = ['93.184.216.34', '192.168.0.10'];
        $this->dns->records['mixed6.example.com'] = ['2606:4700:4700::1111', '::1'];

        $this->assertSame(FetchFailure::BLOCKED_HOST, $this->failureOf('https://mixed.example.com/')->errorCode);
        $this->assertSame(FetchFailure::BLOCKED_HOST, $this->failureOf('https://mixed6.example.com/')->errorCode);
        $this->assertNothingRequested();
    }

    public function test_unresolvable_host_is_unreachable_and_permanent(): void
    {
        $this->dns->records['nxdomain.example.com'] = [];

        $failure = $this->failureOf('https://nxdomain.example.com/');

        $this->assertSame(FetchFailure::UNREACHABLE, $failure->errorCode);
        $this->assertFalse($failure->temporary);
        $this->assertNothingRequested();
    }

    public function test_connection_is_pinned_to_the_checked_address_even_if_dns_changes(): void
    {
        // First answer is public (passes the check); a rebinding resolver would answer
        // 127.0.0.1 on any second lookup. The request must carry the checked address.
        $this->dns->sequences['rebind.example.com'] = [['93.184.216.34'], ['127.0.0.1'], ['127.0.0.1']];
        $this->transport->on('https://rebind.example.com/page', FakeTransport::html(self::PAGE));

        $this->fetch('https://rebind.example.com/page');

        $this->assertCount(1, $this->transport->requests);
        $target = $this->transport->requests[0]['target'];
        $this->assertSame('93.184.216.34', $target->ip);
        $this->assertSame('rebind.example.com', $target->host, 'Host/SNI still use the hostname.');
        $this->assertSame(1, count(array_keys($this->dns->calls, 'rebind.example.com', true)), 'One lookup per hop.');
    }

    public function test_ipv4_is_preferred_and_the_pinned_address_is_one_that_was_checked(): void
    {
        $this->dns->records['dual.example.com'] = ['2606:4700:4700::1111', '1.1.1.1', '8.8.8.8'];
        $this->transport->on('https://dual.example.com', FakeTransport::html(self::PAGE));

        $this->fetch('https://dual.example.com/');

        $this->assertContains($this->transport->requests[0]['target']->ip, ['1.1.1.1', '8.8.8.8']);
    }

    public function test_the_user_host_is_fetched_not_the_stripped_one(): void
    {
        $this->transport->on('https://www.example.com/post', FakeTransport::html(self::PAGE));
        $this->transport->on('https://m.example.com/post', FakeTransport::html(self::PAGE));

        $this->fetch('https://www.example.com/post?utm_source=x#frag');
        $this->fetch('m.example.com/post/');

        $this->assertSame('https://www.example.com/post', $this->transport->requests[0]['target']->url);
        $this->assertSame('https://m.example.com/post', $this->transport->requests[1]['target']->url);
    }

    public function test_redirect_to_a_private_address_is_blocked_and_never_requested(): void
    {
        foreach (['http://127.0.0.1/admin', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'http://10.1.1.1/'] as $location) {
            $this->transport->requests = [];
            $this->transport->on('https://example.com/start', FakeTransport::redirect($location));

            $failure = $this->failureOf('https://example.com/start');

            $this->assertSame(FetchFailure::BLOCKED_HOST, $failure->errorCode, $location);
            $this->assertCount(1, $this->transport->requests, "Only the first hop may be requested ($location).");
        }
    }

    public function test_redirect_to_a_hostname_that_resolves_privately_is_blocked(): void
    {
        $this->dns->records['sneaky.example.net'] = ['192.168.5.5'];
        $this->transport->on('https://example.com/start', FakeTransport::redirect('https://sneaky.example.net/x'));

        $this->assertSame(FetchFailure::BLOCKED_HOST, $this->failureOf('https://example.com/start')->errorCode);
        $this->assertCount(1, $this->transport->requests);
    }

    public function test_redirects_to_other_ports_and_schemes_are_rejected(): void
    {
        $this->transport->on('https://example.com/a', FakeTransport::redirect('https://example.com:8443/x'));
        $this->assertSame(FetchFailure::BLOCKED_PORT, $this->failureOf('https://example.com/a')->errorCode);

        foreach (['file:///etc/passwd', 'ftp://example.com/', 'javascript:alert(1)', 'data:text/html,x', 'gopher://example.com/', 'https://u:p@example.com/'] as $location) {
            $this->transport->on('https://example.com/a', FakeTransport::redirect($location));
            $this->assertSame(FetchFailure::INVALID_URL, $this->failureOf('https://example.com/a')->errorCode, $location);
        }
    }

    public function test_relative_redirects_are_resolved_and_followed(): void
    {
        $this->transport
            ->on('https://example.com/a/b', FakeTransport::redirect('../c'))
            ->on('https://example.com/c', FakeTransport::redirect('/d?x=1', 301))
            ->on('https://example.com/d?x=1', FakeTransport::redirect('//other.example.org/e', 307))
            ->on('https://other.example.org/e', FakeTransport::html(self::PAGE));

        $page = $this->fetch('https://example.com/a/b');

        $this->assertSame('https://other.example.org/e', $page->url, 'The final URL is the base for relative links.');
        $this->assertCount(4, $this->transport->requests);
    }

    public function test_three_redirects_are_followed_but_not_four(): void
    {
        $this->transport
            ->on('https://example.com/1', FakeTransport::redirect('/2'))
            ->on('https://example.com/2', FakeTransport::redirect('/3'))
            ->on('https://example.com/3', FakeTransport::redirect('/4'))
            ->on('https://example.com/4', FakeTransport::html(self::PAGE));
        $this->assertSame(self::PAGE, $this->fetch('https://example.com/1')->body);

        $this->transport->on('https://example.com/4', FakeTransport::redirect('/5'))
            ->on('https://example.com/5', FakeTransport::html(self::PAGE));
        $failure = $this->failureOf('https://example.com/1');

        $this->assertSame(FetchFailure::TOO_MANY_REDIRECTS, $failure->errorCode);
        $this->assertFalse($failure->temporary);
    }

    public function test_redirect_loops_stop(): void
    {
        $this->transport
            ->on('https://example.com/a', FakeTransport::redirect('/b'))
            ->on('https://example.com/b', FakeTransport::redirect('/a'));

        $this->assertSame(FetchFailure::TOO_MANY_REDIRECTS, $this->failureOf('https://example.com/a')->errorCode);
        $this->assertCount(4, $this->transport->requests);
    }

    public function test_redirect_without_location_fails_permanently(): void
    {
        $this->transport->on('https://example.com/a', FakeTransport::status(302));

        $failure = $this->failureOf('https://example.com/a');

        $this->assertSame('HTTP_302', $failure->errorCode);
        $this->assertFalse($failure->temporary);
    }

    #[DataProvider('httpStatuses')]
    public function test_http_status_classification(int $status, bool $temporary): void
    {
        $this->transport->on('https://example.com/a', FakeTransport::status($status));

        $failure = $this->failureOf('https://example.com/a');

        $this->assertSame("HTTP_$status", $failure->errorCode);
        $this->assertSame($temporary, $failure->temporary);
        $this->assertFalse($failure->blocked);
    }

    public static function httpStatuses(): array
    {
        return [
            '429 retried' => [429, true], '500 retried' => [500, true], '502 retried' => [502, true], '503 retried' => [503, true],
            '504 retried' => [504, true], '599 retried' => [599, true],
            '400 permanent' => [400, false], '401 permanent' => [401, false], '403 permanent' => [403, false],
            '404 permanent' => [404, false], '410 permanent' => [410, false], '451 permanent' => [451, false],
            '304 permanent' => [304, false], '300 permanent' => [300, false],
        ];
    }

    #[DataProvider('nonHtmlTypes')]
    public function test_only_html_is_accepted(string $contentType): void
    {
        $this->transport->on('https://example.com/a', FakeTransport::html('x', 200, $contentType));

        $this->assertSame(FetchFailure::NOT_HTML, $this->failureOf('https://example.com/a')->errorCode);
    }

    public static function nonHtmlTypes(): array
    {
        $types = ['application/json', 'image/png', 'image/svg+xml', 'video/mp4', 'audio/mpeg', 'application/zip', 'application/pdf',
            'application/octet-stream', 'text/plain', 'text/xml', 'application/xml', 'text/html5', ''];

        return array_combine($types, array_map(fn ($t) => [$t], $types));
    }

    public function test_html_and_xhtml_are_accepted_with_any_case_and_parameters(): void
    {
        foreach (['text/html', 'TEXT/HTML; Charset=UTF-8', 'text/html;charset=windows-1256', 'application/xhtml+xml', ' text/html '] as $type) {
            $this->transport->on('https://example.com/a', FakeTransport::html(self::PAGE, 200, $type));

            $this->assertSame(self::PAGE, $this->fetch('https://example.com/a')->body, $type);
        }
    }

    public function test_the_size_limit_is_passed_to_the_transport_and_truncation_is_reported(): void
    {
        $this->transport->on('https://example.com/a', new TransportResponse(200, ['content-type' => 'text/html'], 'partial', true));

        $page = $this->fetch('https://example.com/a');

        $this->assertTrue($page->truncated);
        $this->assertSame(512 * 1024, $this->transport->requests[0]['maxBytes']);
    }

    public function test_transport_timeouts_and_connection_errors_propagate(): void
    {
        $this->transport->on('https://example.com/slow', FetchFailure::timeout());
        $this->transport->on('https://example.com/refused', FetchFailure::unreachable());
        $this->transport->on('https://example.com/badtls', FetchFailure::tls());

        $timeout = $this->failureOf('https://example.com/slow');
        $this->assertSame(FetchFailure::TIMEOUT, $timeout->errorCode);
        $this->assertTrue($timeout->temporary);

        $this->assertFalse($this->failureOf('https://example.com/refused')->temporary);
        $this->assertSame(FetchFailure::TLS_ERROR, $this->failureOf('https://example.com/badtls')->errorCode);
    }

    public function test_one_time_budget_is_shared_across_all_hops(): void
    {
        $this->transport->delay = 0.25;
        $this->transport
            ->on('https://example.com/1', FakeTransport::redirect('/2'))
            ->on('https://example.com/2', FakeTransport::redirect('/3'))
            ->on('https://example.com/3', FakeTransport::html(self::PAGE));

        $failure = $this->failureOf('https://example.com/1', timeout: 0.4);

        $this->assertSame(FetchFailure::TIMEOUT, $failure->errorCode);
        $this->assertTrue($failure->temporary);
        $this->assertLessThan(3, count($this->transport->requests), 'The third hop must not start after the budget is spent.');

        $timeouts = array_column($this->transport->requests, 'timeout');
        $this->assertLessThanOrEqual(0.4, $timeouts[0]);
        $this->assertLessThan($timeouts[0], $timeouts[1], 'Each hop gets only the remaining time.');
    }

    public function test_the_first_hop_gets_the_full_budget_but_never_more(): void
    {
        $this->transport->on('https://example.com/a', FakeTransport::html(self::PAGE));

        $this->fetch('https://example.com/a', timeout: 4.0);

        $this->assertLessThanOrEqual(4.0, $this->transport->requests[0]['timeout']);
        $this->assertGreaterThan(3.5, $this->transport->requests[0]['timeout']);
    }

    public function test_the_fake_resolver_default_is_public_so_ordinary_hosts_work(): void
    {
        $this->assertSame([FakeDnsResolver::PUBLIC_IP], $this->dns->resolve('anything.example.com'));
    }
}
