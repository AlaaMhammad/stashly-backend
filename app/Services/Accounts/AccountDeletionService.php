<?php

namespace App\Services\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * API-PROF-005: permanently removes the account, its tokens and all its data.
 *
 * Tables added in later phases (links, collections, tags, idempotency records)
 * must reference users with cascadeOnDelete() and be covered by
 * AccountDeletionTest, so this stays a single atomic delete.
 */
class AccountDeletionService
{
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();

            DB::table(config('auth.passwords.users.table'))
                ->where('email', $user->getEmailForPasswordReset())
                ->delete();

            DB::table('password_reset_codes')
                ->where('email', $user->getEmailForPasswordReset())
                ->delete();

            DB::table('sessions')->where('user_id', $user->getKey())->delete();

            $user->delete();
        });

        // Audit trail without personal data: the ULID only.
        Log::info('Account deleted.', ['user_id' => $user->getKey()]);
    }
}
