<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Additive (DEC-041): every existing collection stays private.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->index();
            // Unguessable, human-friendly address of the public read-only feed; kept when sharing is switched off.
            $table->string('share_slug', 80)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropUnique(['share_slug']);
            $table->dropIndex(['is_public']);
            $table->dropColumn(['is_public', 'share_slug']);
        });
    }
};
