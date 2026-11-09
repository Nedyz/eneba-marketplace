<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The digital keys being sold. Created after order_items because a key points
 * to the order item that reserved or bought it.
 *
 * available -> reserved (by a pending order, until reserved_until) -> sold.
 * A reservation whose reserved_until has passed counts as available again, so
 * stock never depends on a cleanup job (DECISIONS.md D10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            // Encrypted with APP_KEY (Eloquent `encrypted` cast). text, because the
            // ciphertext is a base64 JSON payload much longer than the code itself.
            // No unique index: every encryption of the same code is different (D9).
            $table->text('code');
            $table->enum('status', ['available', 'reserved', 'sold'])->default('available');
            $table->timestamp('reserved_until')->nullable();
            // nullOnDelete is never expected to fire (orders are not deleted); it only
            // avoids a key pointing to a missing row if that ever happens.
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();

            // Reserve query and stock count: WHERE offer_id = ? AND status ...
            $table->index(['offer_id', 'status']);
            // Keys of an order (pay step, key reveal).
            $table->index('order_item_id');
        });

        // Keep the row consistent with its status:
        // a sold key always belongs to an order item and has a sale time,
        // a reserved key always belongs to an order item and has a deadline.
        DB::statement("ALTER TABLE product_keys ADD CONSTRAINT product_keys_state_consistent CHECK (
            (status = 'available')
            OR (status = 'reserved' AND order_item_id IS NOT NULL AND reserved_until IS NOT NULL)
            OR (status = 'sold' AND order_item_id IS NOT NULL AND sold_at IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_keys');
    }
};
