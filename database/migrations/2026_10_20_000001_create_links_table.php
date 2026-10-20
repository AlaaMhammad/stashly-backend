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
        Schema::create('links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Account deletion removes links (API-PROF-005).
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('original_url', 2048);
            $table->string('normalized_url', 2048);
            // sha256 of normalized_url: a 2048-char utf8mb4 column exceeds MySQL's index key limit.
            $table->char('normalized_url_hash', 64);
            $table->string('source_domain', 253)->index();

            $table->string('title', 300)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->string('content_type', 16)->default('other');

            $table->string('metadata_status', 16)->default('pending');
            $table->text('metadata_title')->nullable();
            $table->text('metadata_description')->nullable();
            $table->string('metadata_site_name', 255)->nullable();
            $table->text('metadata_favicon_url')->nullable();
            $table->text('metadata_preview_image_url')->nullable();
            $table->timestamp('metadata_fetched_at')->nullable();
            $table->string('metadata_error_code', 64)->nullable();

            $table->timestamps();

            // One saved link per user per normalized URL (contract 4.1).
            $table->unique(['user_id', 'normalized_url_hash']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
