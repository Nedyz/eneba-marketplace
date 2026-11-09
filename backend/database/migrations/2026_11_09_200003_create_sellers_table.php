<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sellers are seeded data only (no seller accounts, see DECISIONS.md D5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // numeric(3,2): exact decimal 0.00-9.99, the check narrows it to 0-5.
            $table->decimal('rating', 3, 2);
            $table->timestamps();
        });

        // Laravel's schema builder has no fluent API for CHECK constraints, so raw SQL.
        DB::statement('ALTER TABLE sellers ADD CONSTRAINT sellers_rating_range CHECK (rating BETWEEN 0 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
