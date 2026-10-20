<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('key');
            // sha256 of the canonical request body; a reused key with another body is rejected.
            $table->char('request_fingerprint', 64);
            $table->unsignedSmallInteger('response_status');
            $table->longText('response_body');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->index();

            $table->unique(['user_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
