<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle (PLAN.md "Checkout and key assignment"):
 * pending (keys reserved, 15 minutes) -> paid | failed | expired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Orders are financial records: a user with orders cannot be deleted silently.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // On PostgreSQL, enum() creates varchar + CHECK constraint (not a native enum
            // type), so adding a status later is a simple constraint change (D19).
            $table->enum('status', ['pending', 'paid', 'failed', 'expired'])->default('pending');
            $table->integer('total_cents');
            $table->char('currency', 3)->default('EUR');
            $table->timestamp('expires_at');
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // Order history: WHERE user_id = ? ORDER BY created_at DESC.
            $table->index(['user_id', 'created_at']);
            // Lazy expiry: UPDATE ... WHERE status = 'pending' AND expires_at < now().
            $table->index(['status', 'expires_at']);
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_not_negative CHECK (total_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
