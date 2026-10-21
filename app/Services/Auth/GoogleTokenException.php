<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A Google ID token that must not be trusted. The reason is a short code for
 * logs and tests; it never contains the token or any claim value.
 */
final class GoogleTokenException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const MALFORMED = 'malformed';

    public const ALGORITHM = 'algorithm';

    public const UNKNOWN_KEY = 'unknown_key';

    public const SIGNATURE = 'signature';

    public const ISSUER = 'issuer';

    public const AUDIENCE = 'audience';

    public const EXPIRED = 'expired';

    public const NOT_YET_VALID = 'not_yet_valid';

    public const CLAIMS = 'claims';

    public const EMAIL_UNVERIFIED = 'email_unverified';

    public const KEYS_UNAVAILABLE = 'keys_unavailable';

    public const ACCOUNT_CONFLICT = 'account_conflict';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Google ID token rejected: '.$reason);
    }

    /**
     * The API error for this rejection; logs the reason code only.
     */
    public function toApiException(): ApiException
    {
        Log::info('Google sign-in rejected.', ['reason' => $this->reason]);

        return match ($this->reason) {
            self::NOT_CONFIGURED => ApiException::googleUnavailable('Google sign-in is not configured.'),
            self::KEYS_UNAVAILABLE => ApiException::googleUnavailable('Google sign-in is temporarily unavailable.'),
            self::EMAIL_UNVERIFIED => ApiException::invalidGoogleToken('The email address of this Google account is not verified.'),
            self::ACCOUNT_CONFLICT => ApiException::invalidGoogleToken('This email address is already linked to a different Google account.'),
            default => ApiException::invalidGoogleToken('The Google sign-in token is invalid or has expired.'),
        };
    }
}
