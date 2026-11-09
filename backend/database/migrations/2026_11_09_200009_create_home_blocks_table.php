<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-driven homepage layout (DECISIONS.md D4). GET /api/home returns the
 * active rows in `position` order; the frontend maps `type` to a component and
 * that component fetches `endpoint`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_blocks', function (Blueprint $table) {
            $table->id();
            // Plain string, not enum: the frontend skips unknown types, so new
            // types must be addable without a schema change.
            $table->string('type');          // banner, product_carousel
            $table->string('title')->nullable();
            // Path relative to the API base (e.g. /home/deals), never a full URL.
            $table->string('endpoint');
            $table->integer('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_blocks');
    }
};
