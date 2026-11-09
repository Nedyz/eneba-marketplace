<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One product = one edition: a game on one platform for one region
 * (DECISIONS.md D5). "Elden Ring" on Steam/Global and on PlayStation/Europe are
 * two products. `name` holds only the game title; platform and region are shown
 * from their own columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // e.g. elden-ring-steam-global
            $table->string('name');
            $table->text('description');
            // Null = the frontend draws a generated cover (DECISIONS.md D15).
            $table->string('image_url')->nullable();
            // restrictOnDelete: a platform/region still used by products cannot be deleted.
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->date('release_date')->nullable();
            $table->timestamps();
            // foreignId()->constrained() adds the FK but not an index in PostgreSQL;
            // the store filters on these columns, so index them explicitly.
            $table->index('platform_id');
            $table->index('region_id');
        });

        // Trigram index for search (needs pg_trgm, enabled by an earlier migration).
        // GIN + gin_trgm_ops serves both `name ILIKE '%term%'` (partial names) and
        // `term <% name` (word similarity, typos). It is on `name`, not `lower(name)`:
        // pg_trgm lowercases text when it builds trigrams, so case does not matter,
        // and queries can use plain `name` instead of repeating the exact expression.
        DB::statement('CREATE INDEX products_name_trgm_idx ON products USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products'); // drops its indexes too
    }
};
