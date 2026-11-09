<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One line of an order. Price and names are copied at purchase time, so the
 * order history still shows what was paid if the offer or product changes later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // restrict: an offer that has been ordered cannot be deleted.
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->integer('quantity');
            $table->integer('unit_price_cents');
            $table->string('product_name');
            $table->string('seller_name');
            $table->timestamps();

            // The cart groups by offer, so an order has at most one line per offer.
            $table->unique(['order_id', 'offer_id']);
            // Best sellers aggregates sold items per offer.
            $table->index('offer_id');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_price_positive CHECK (unit_price_cents > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
