<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TEMPORARY, reverted right after the check (DECISIONS.md D19).
 * Fails on purpose to observe what Render does when a migration fails at
 * container start. SELECT 1/0 changes nothing before failing, and on
 * PostgreSQL the migration runs in its own transaction anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SELECT 1/0');
    }

    public function down(): void {}
};
