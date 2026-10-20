<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            // Identifies the current lookup; jobs from superseded lookups do nothing.
            $table->string('metadata_request_id', 26)->nullable();
            // When the lookup was requested, or when its scheduled retry is due (DEC-006).
            $table->timestamp('metadata_pending_since')->nullable();
            $table->unsignedTinyInteger('metadata_attempts')->default(0);

            $table->index(['metadata_status', 'metadata_pending_since']);
        });

        DB::table('links')->where('metadata_status', 'pending')->update(['metadata_pending_since' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropIndex(['metadata_status', 'metadata_pending_since']);
            $table->dropColumn(['metadata_request_id', 'metadata_pending_since', 'metadata_attempts']);
        });
    }
};
