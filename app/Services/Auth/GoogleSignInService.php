<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Turns a verified GoogleIdentity into a local user (DEC-035):
 *  1. a user with this google_id            -> sign in;
 *  2. else a user with this e-mail          -> link the google_id (Google asserted the e-mail
 *     is verified), revoke that user's existing tokens, sign in;
 *  3. else                                  -> create a Google-only account (needs accepted terms).
 */
class GoogleSignInService
{
    /**
     * @return array{0: User, 1: bool} the user and whether the account was just created
     *
     * @throws ValidationException new account without accepted terms
     * @throws GoogleTokenException the e-mail belongs to a different Google account
     */
    public function resolve(GoogleIdentity $identity, bool $termsAccepted, ?string $termsVersion): array
    {
        try {
            return $this->resolveOnce($identity, $termsAccepted, $termsVersion);
        } catch (UniqueConstraintViolationException) {
            // A concurrent first sign-in created the same account; resolve against it.
            return $this->resolveOnce($identity, $termsAccepted, $termsVersion);
        }
    }

    /**
     * @return array{0: User, 1: bool}
     */
    private function resolveOnce(GoogleIdentity $identity, bool $termsAccepted, ?string $termsVersion): array
    {
        return DB::transaction(function () use ($identity, $termsAccepted, $termsVersion) {
            $user = User::query()->where('google_id', $identity->subject)->lockForUpdate()->first();

            if ($user !== null) {
                $this->refreshProfile($user, $identity, $termsAccepted, $termsVersion);

                return [$user, false];
            }

            $user = User::query()->where('email', $identity->email)->lockForUpdate()->first();

            if ($user !== null) {
                if ($user->google_id !== null) {
                    // Same e-mail, different Google account: never take over a linked account.
                    throw new GoogleTokenException(GoogleTokenException::ACCOUNT_CONFLICT);
                }

                // DEC-037: registration never verified the e-mail, so whoever registered it first
                // loses both their sessions and their password at the moment of linking. A
                // standalone password can be set again later through forgot-password.
                $user->forceFill(['google_id' => $identity->subject, 'email_verified_at' => $user->email_verified_at ?? now(), 'password' => null]);
                $this->refreshProfile($user, $identity, $termsAccepted, $termsVersion);
                $user->tokens()->delete();
                Log::info('Google account linked.', ['user_id' => $user->getKey()]);

                return [$user, false];
            }

            if (! $termsAccepted) {
                throw ValidationException::withMessages(['terms_accepted' => 'You must accept the terms to create an account.']);
            }

            $user = new User;
            $user->forceFill([
                'display_name' => $this->displayName($identity),
                'email' => $identity->email,
                'google_id' => $identity->subject,
                'avatar_url' => $identity->picture,
                'password' => null,
                'email_verified_at' => now(),
                'terms_version' => $termsVersion ?? config('stashly.terms.version'),
                'terms_accepted_at' => now(),
            ])->save();
            Log::info('Account created with Google.', ['user_id' => $user->getKey()]);

            return [$user, true];
        });
    }

    /**
     * Keeps the picture current and records a (re-)acceptance of the terms when the
     * client sends terms_accepted for a version the user has not accepted yet.
     */
    private function refreshProfile(User $user, GoogleIdentity $identity, bool $termsAccepted, ?string $termsVersion): void
    {
        $user->forceFill(['avatar_url' => $identity->picture]);

        if ($termsAccepted) {
            $version = $termsVersion ?? config('stashly.terms.version');
            if ($user->terms_version !== $version) {
                $user->forceFill(['terms_version' => $version, 'terms_accepted_at' => now()]);
            }
        }

        $user->save();
    }

    private function displayName(GoogleIdentity $identity): string
    {
        $clean = fn (?string $value) => trim((string) preg_replace('/[\p{C}\s]+/u', ' ', (string) $value));
        $max = 100; // users.display_name is limited to 100 characters

        $name = $clean($identity->name);
        if ($name === '') {
            $name = $clean(strstr($identity->email, '@', true));
        }

        return mb_substr($name, 0, $max);
    }
}
