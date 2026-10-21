<?php

namespace App\Services\Auth;

/**
 * The verified identity from a Google ID token. Only built after signature,
 * issuer, audience, expiry and email_verified have all been checked.
 */
final class GoogleIdentity
{
    public function __construct(
        /** Google's stable account id ("sub"). */
        public readonly string $subject,
        /** Lowercased, verified e-mail. */
        public readonly string $email,
        public readonly ?string $name,
        /** https address of the profile picture, or null. */
        public readonly ?string $picture,
    ) {}
}
