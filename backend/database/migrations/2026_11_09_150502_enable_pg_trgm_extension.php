<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pg_trgm splits text into trigrams (3-character chunks) and compares them,
 * which is what makes typo-tolerant search possible ("eldn ring" ~ "Elden Ring").
 *
 * Enabling it in a migration means every database (local, test, Neon, CI) sets
 * itself up on `php artisan migrate`, instead of relying on someone running
 * CREATE EXTENSION by hand. It runs before the products migration, which
 * creates a trigram index and would fail without the extension.
 */
return new class extends Migration
{
    public function up(): void
    {
        // IF NOT EXISTS: safe to run on a database where it is already enabled.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        // Without CASCADE this fails if a trigram index still exists, on purpose:
        // rolling back should never silently drop our search indexes.
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
    }
};
