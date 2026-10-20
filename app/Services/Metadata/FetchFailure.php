<?php

namespace App\Services\Metadata;

use RuntimeException;

/**
 * A metadata lookup that did not produce a page. The stable error code is
 * stored in links.metadata_error_code; blocked codes end in status "blocked".
 * Only temporary failures (HTTP 429, 5xx, timeout) are retried (contract 4.2).
 */
final class FetchFailure extends RuntimeException
{
    public const INVALID_URL = 'INVALID_URL';

    public const BLOCKED_HOST = 'BLOCKED_HOST';

    public const BLOCKED_PORT = 'BLOCKED_PORT';

    public const UNREACHABLE = 'UNREACHABLE';

    public const TLS_ERROR = 'TLS_ERROR';

    public const TIMEOUT = 'TIMEOUT';

    public const TOO_MANY_REDIRECTS = 'TOO_MANY_REDIRECTS';

    public const NOT_HTML = 'NOT_HTML';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    public const STALE_PENDING = 'STALE_PENDING';

    private function __construct(
        public readonly string $errorCode,
        public readonly bool $temporary = false,
        public readonly bool $blocked = false,
    ) {
        parent::__construct('Metadata lookup failed: '.$errorCode);
    }

    public static function invalidUrl(): self
    {
        return new self(self::INVALID_URL);
    }

    public static function blockedHost(): self
    {
        return new self(self::BLOCKED_HOST, blocked: true);
    }

    public static function blockedPort(): self
    {
        return new self(self::BLOCKED_PORT, blocked: true);
    }

    public static function unreachable(): self
    {
        return new self(self::UNREACHABLE);
    }

    public static function tls(): self
    {
        return new self(self::TLS_ERROR);
    }

    public static function timeout(): self
    {
        return new self(self::TIMEOUT, temporary: true);
    }

    public static function tooManyRedirects(): self
    {
        return new self(self::TOO_MANY_REDIRECTS);
    }

    public static function notHtml(): self
    {
        return new self(self::NOT_HTML);
    }

    public static function http(int $status): self
    {
        return new self('HTTP_'.$status, temporary: $status === 429 || $status >= 500);
    }
}
