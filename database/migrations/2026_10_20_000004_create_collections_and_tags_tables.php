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
        // MySQL's default *_ci collations also ignore accents ("café" = "cafe").
        // Uniqueness keys are already lowercased, so compare them byte-for-byte.
        $binary = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? 'utf8mb4_bin' : null;

        Schema::create('collections', function (Blueprint $table) use ($binary) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // Lowercased name: "unique per user, ignoring case" (contract 2.5).
            $table->string('name_key', 60)->collation($binary);
            $table->string('description', 300)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name_key']);
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('collection_link', function (Blueprint $table) {
            $table->foreignUlid('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('link_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['collection_id', 'link_id']);
            $table->index('link_id');
        });

        Schema::create('tags', function (Blueprint $table) use ($binary) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            // Normalized name (trim, lowercase, no leading #, single spaces).
            $table->string('name', 40)->collation($binary);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('link_tag', function (Blueprint $table) {
            $table->foreignUlid('link_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['link_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('link_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('collection_link');
        Schema::dropIfExists('collections');
    }
};
