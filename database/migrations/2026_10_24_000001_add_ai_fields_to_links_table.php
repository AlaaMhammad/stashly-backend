<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Additive (DEC-038): existing rows are unchanged and stay NULL
     * until the user asks for a summary.
     */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->text('ai_summary')->nullable();
            $table->json('ai_tags')->nullable();
            $table->timestamp('ai_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn(['ai_summary', 'ai_tags', 'ai_generated_at']);
        });
    }
};
