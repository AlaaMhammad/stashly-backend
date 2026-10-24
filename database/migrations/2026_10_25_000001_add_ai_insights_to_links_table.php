<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Additive (DEC-039): cached AI results, NULL until requested.
     */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->json('ai_insights')->nullable();
            $table->json('ai_action_plan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn(['ai_insights', 'ai_action_plan']);
        });
    }
};
