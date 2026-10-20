<?php

namespace App\Services\Metadata;

/**
 * Raw curl transport. Laravel's HTTP client cannot pin the connection to a
 * checked IP, so this uses CURLOPT_RESOLVE: the TCP connection goes to the
 * address the guard verified, while the URL host still drives the Host
 * header, TLS SNI and certificate verification (security/ssrf.md).
 *
 * Also: redirects are never followed here (SafeFetcher re-checks each one),
 * no proxy (even from the environment), no cookies, only http/https, the
 * body stops at the size limit (decoded size, so compression bombs are
 * capped), and non-2xx or non-HTML bodies are not read at all.
 */
class CurlTransport implements HttpTransport
{
    /**
     * libcurl error numbers for TLS problems (several have no PHP constant):
     * 35 handshake, 51/60 certificate not trusted, 53/54 engine, 58/66/77/80/83 local
     * cert/CA/issuer problems, 59 cipher, 64 TLS required but failed, 82 CRL.
     */
    private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83];

    public function __construct(private readonly string $userAgent = 'StashlyBot/1.0') {}

    public function request(Target $target, float $timeoutSeconds, int $maxBytes): TransportResponse
    {
        $status = 0;
        $headers = [];
        $body = '';
        $truncated = false;
        $discard = false;
        $decided = false;

        $handle = curl_init($target->url);
        $options = [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
                'Accept-Language: en,ar;q=0.8',
            ],
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($timeoutSeconds * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ceil($timeoutSeconds * 1000)),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$status, &$headers) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
                    $status = (int) $m[1];
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$status, &$headers, &$body, &$truncated, &$discard, &$decided, $maxBytes) {
                if (! $decided) {
                    $decided = true;
                    $discard = ! ($status >= 200 && $status < 300 && TransportResponse::isHtmlType($headers['content-type'] ?? ''));
                }
                if ($discard) {
                    return 0;
                }

                $room = $maxBytes - strlen($body);
                if (strlen($chunk) > $room) {
                    $body .= substr($chunk, 0, $room);
                    $truncated = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ];

        if (! $target->hostIsLiteral()) {
            $options[CURLOPT_RESOLVE] = ["{$target->host}:{$target->port}:{$target->ip}"];
        }

        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $errno = curl_errno($handle);

        // Aborting the body on purpose (discard or size limit) reports a write error.
        $deliberate = $errno === CURLE_WRITE_ERROR && ($discard || $truncated);
        if ($ok === false && ! $deliberate) {
            throw match (true) {
                $errno === 28 => FetchFailure::timeout(),
                in_array($errno, self::TLS_ERRORS, true) => FetchFailure::tls(),
                default => FetchFailure::unreachable(),
            };
        }

        if ($status === 0) {
            throw FetchFailure::unreachable();
        }

        return new TransportResponse($status, $headers, $discard ? '' : $body, $truncated);
    }
}
