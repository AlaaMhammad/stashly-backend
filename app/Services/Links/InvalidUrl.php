<?php

namespace App\Services\Links;

use InvalidArgumentException;

/**
 * A URL the contract does not accept: bad scheme, length, host or credentials.
 * The reason is for tests and diagnostics; clients only see INVALID_URL.
 */
final class InvalidUrl extends InvalidArgumentException
{
    public const EMPTY = 'empty';

    public const TOO_LONG = 'too_long';

    public const INVALID_CHARACTERS = 'invalid_characters';

    public const UNSUPPORTED_SCHEME = 'unsupported_scheme';

    public const CREDENTIALS = 'credentials';

    public const INVALID_HOST = 'invalid_host';

    public const INVALID_PORT = 'invalid_port';

    private function __construct(public readonly string $reason)
    {
        parent::__construct('URL rejected: '.$reason);
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
