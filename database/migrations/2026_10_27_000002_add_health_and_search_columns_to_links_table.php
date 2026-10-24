<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Additive (DEC-041): links start as not broken and never checked.
     */
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->boolean('is_broken')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            // Lowercased display title (title, else fetched title, else domain): the keyset column for sort=title.
            $table->string('sort_title', 255)->nullable();

            // GET /links/broken and the compound search always start from one user's links.
            $table->index(['user_id', 'is_broken', 'created_at'], 'links_user_broken_created_index');
            $table->index(['user_id', 'is_favorite', 'created_at'], 'links_user_favorite_created_index');
            $table->index(['user_id', 'sort_title', 'id'], 'links_user_sort_title_index');
        });

        DB::table('links')->orderBy('id')->chunkById(500, function ($links) {
            foreach ($links as $link) {
                DB::table('links')->where('id', $link->id)->update([
                    'sort_title' => mb_substr(mb_strtolower((string) ($link->title ?? $link->metadata_title ?? $link->source_domain)), 0, 255),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropIndex('links_user_broken_created_index');
            $table->dropIndex('links_user_favorite_created_index');
            $table->dropIndex('links_user_sort_title_index');
            $table->dropColumn(['is_broken', 'last_checked_at', 'sort_title']);
        });
    }
};
