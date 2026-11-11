<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two new order statuses for checkout (D34):
 *
 * - paying:    the payment is being charged. Set (under a row lock) before the
 *              gateway is called, so a second pay request for the same order
 *              (double click, second tab) sees it and never charges again.
 * - cancelled: replaced by a newer order of the same user (one pending order
 *              per user); its keys are released at that moment.
 *
 * Laravel's enum() on PostgreSQL is varchar + a CHECK constraint named
 * orders_status_check (D19), so adding a value is replacing that constraint:
 * no table rewrite, and the existing rows are checked against the new list.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check
            CHECK (status IN ('pending', 'paying', 'paid', 'failed', 'expired', 'cancelled'))");
    }

    public function down(): void
    {
        // Rows with a new status would violate the old constraint. A rollback
        // maps them to the closest old meaning: an interrupted payment failed,
        // a replaced order expired (both have released their keys, or will on
        // the next lazy expiry).
        DB::statement("UPDATE orders SET status = 'failed' WHERE status = 'paying'");
        DB::statement("UPDATE orders SET status = 'expired' WHERE status = 'cancelled'");
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check
            CHECK (status IN ('pending', 'paid', 'failed', 'expired'))");
    }
};
