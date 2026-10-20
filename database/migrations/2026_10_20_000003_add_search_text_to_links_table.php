<?php

use App\Support\Search\SearchText;
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
            // Normalized title, fetched title, description, note and domain for q search.
            $table->text('search_text')->nullable();

            // Keyset pagination orders by (created_at, id) within one user's links.
            $table->dropIndex(['user_id', 'created_at']);
            $table->index(['user_id', 'created_at', 'id']);
        });

        DB::table('links')->orderBy('id')->chunkById(500, function ($links) {
            foreach ($links as $link) {
                DB::table('links')->where('id', $link->id)->update(['search_text' => SearchText::index([
                    $link->title, $link->metadata_title, $link->metadata_description, $link->note, $link->source_domain,
                ])]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at', 'id']);
            $table->index(['user_id', 'created_at']);
            $table->dropColumn('search_text');
        });
    }
};
