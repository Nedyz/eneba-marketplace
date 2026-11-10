<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content of the homepage banner block (GET /api/home/banners).
 * starts_at / ends_at are optional: null means no limit on that side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('image_url')->nullable(); // null = frontend draws a background
            $table->string('cta_label');
            $table->string('link_url');  // internal frontend route, e.g. /store?platform=steam
            $table->integer('position');
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
