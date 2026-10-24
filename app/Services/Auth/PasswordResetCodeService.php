<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Password reset by a 6-digit code (DEC-040), replacing the reset link. One active code per
 * e-mail: a new request replaces the old one. The code lives 15 minutes, allows 5 wrong guesses,
 * and is stored only as a keyed hash. It is deleted when used, expired or guessed against too often.
 */
class PasswordResetCodeService
{
    private const TABLE = 'password_reset_codes';

    /**
     * Create (or replace) the code for $user and return it in clear text, or null when a code
     * was issued less than the resend cooldown ago (the earlier e-mail is still valid).
     */
    public function issue(User $user): ?string
    {
        $email = $user->getEmailForPasswordReset();
        $cooldown = (int) config('stashly.password_reset.resend_cooldown_seconds');

        return DB::transaction(function () use ($email, $cooldown) {
            $existing = DB::table(self::TABLE)->where('email', $email)->lockForUpdate()->first();

            if ($existing !== null && now()->subSeconds($cooldown)->lt($existing->created_at)) {
                return null;
            }

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table(self::TABLE)->updateOrInsert(['email' => $email], [
                'code_hash' => $this->hash($email, $code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes((int) config('stashly.password_reset.code_ttl_minutes')),
                'created_at' => now(),
            ]);

            return $code;
        });
    }

    /**
     * Send the code right away, without a queue worker, but after the HTTP response is sent:
     * the API answers at the same speed for known and unknown e-mails and a slow or failing SMTP
     * server never changes the response. A failure is logged (error class and a redacted message:
     * no address, no code) and the unusable code is deleted so the user can ask again at once.
     */
    public function send(User $user, string $code): void
    {
        defer(function () use ($user, $code) {
            try {
                $user->notifyNow(new PasswordResetCodeNotification($code));
            } catch (Throwable $e) {
                Log::error('Password reset email could not be sent.', [
                    'user_id' => $user->getKey(),
                    'exception' => $e::class,
                    'detail' => mb_substr((string) preg_replace('/[^\s"\'<>]+@[^\s"\'<>]+/', '[email]', $e->getMessage()), 0, 200),
                ]);

                $this->forget($user->getEmailForPasswordReset());
            }
        });
    }

    /**
     * Is $code the current code for $email? A wrong guess is counted and the code is deleted after
     * the allowed number of wrong guesses; $consume deletes it on success (single use).
     */
    public function check(string $email, string $code, bool $consume = false): bool
    {
        return DB::transaction(function () use ($email, $code, $consume) {
            $row = DB::table(self::TABLE)->where('email', $email)->lockForUpdate()->first();
            // Computed even for an unknown e-mail so the work done does not depend on it.
            $given = $this->hash($email, $code);

            if ($row === null) {
                return false;
            }

            if (now()->gte($row->expires_at)) {
                $this->forget($email);

                return false;
            }

            if (hash_equals($row->code_hash, $given)) {
                if ($consume) {
                    $this->forget($email);
                }

                return true;
            }

            if ($row->attempts + 1 >= (int) config('stashly.password_reset.max_attempts')) {
                $this->forget($email);
            } else {
                DB::table(self::TABLE)->where('email', $email)->increment('attempts');
            }

            return false;
        });
    }

    public function forget(string $email): void
    {
        DB::table(self::TABLE)->where('email', $email)->delete();
    }

    private function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email.'|'.$code, (string) config('app.key'));
    }
}
