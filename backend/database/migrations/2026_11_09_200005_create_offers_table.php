<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A seller's price for a product. The product page compares these.
 * Money is integer cents (DECISIONS.md D12). Stock is not a column: it is the
 * number of available keys of the offer (see product_keys).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            // Deleting a product deletes its offers... unless an offer was sold:
            // order_items restricts deletion of sold offers, which then blocks this too.
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->integer('price_cents');
            // Set = the offer is a deal (shown crossed out). Null = no discount.
            $table->integer('original_price_cents')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One offer per seller per product. Also serves as the index for product_id lookups
            // (product_id is the leading column).
            $table->unique(['product_id', 'seller_id']);
            $table->index('seller_id');
        });

        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_price_positive CHECK (price_cents > 0)');
        // A "deal" must actually be cheaper than the original price.
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_original_price_above_price
            CHECK (original_price_cents IS NULL OR original_price_cents > price_cents)');
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
