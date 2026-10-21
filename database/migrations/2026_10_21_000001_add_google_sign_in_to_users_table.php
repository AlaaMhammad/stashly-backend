<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations. Additive: existing rows and the password login are unchanged.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Google "sub" claim: the stable account identifier (the e-mail can change).
            $table->string('google_id')->nullable()->unique()->after('email');
            // Profile picture address from Google; stored only, not part of the API user object.
            $table->string('avatar_url', 2048)->nullable()->after('google_id');
            // Google-only accounts have no password. Password login rejects a null hash.
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations. Accounts without a password get an unusable random hash
     * first (they could sign in only with Google, which no longer exists after rollback;
     * the password-reset flow can set a real one).
     */
    public function down(): void
    {
        DB::table('users')->whereNull('password')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update(['password' => Hash::make(Str::random(64))]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'avatar_url']);
        });
    }
};
