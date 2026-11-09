<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the store query (Services/Catalog/ProductQuery). Each was chosen
 * from EXPLAIN ANALYZE on a 50k-product benchmark (DECISIONS.md D23).
 * Laravel's schema builder cannot express partial or NULLS LAST indexes, hence
 * raw SQL. Additive only, safe for D19.
 */
return new class extends Migration
{
    public function up(): void
    {
        // From price, per product: walk active offers already in (price, id) order and
        // stop at the first one with stock. `id` is included because the query breaks
        // price ties by id; without it Postgres sorts each product's offers first.
        // Partial (WHERE is_active): inactive offers are never priced.
        DB::statement('CREATE INDEX offers_active_product_price_idx ON offers (product_id, price_cents, id) WHERE is_active');

        // Stock check, per offer: only keys that can still be sold. Sold keys pile up
        // forever; without this, an offer with thousands of sold keys would read them
        // all to find one available key.
        DB::statement("CREATE INDEX product_keys_unsold_offer_idx ON product_keys (offer_id) WHERE status IN ('available', 'reserved')");

        // Sorts that do not depend on price: Postgres reads products already in order
        // and computes the from price only for the rows on the page (not all products).
        // Must match ORDER BY exactly, including NULLS LAST and the id tie-breaker.
        DB::statement('CREATE INDEX products_newest_idx ON products (release_date DESC NULLS LAST, id)');
        DB::statement('CREATE INDEX products_name_idx ON products (name, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_name_idx');
        DB::statement('DROP INDEX IF EXISTS products_newest_idx');
        DB::statement('DROP INDEX IF EXISTS product_keys_unsold_offer_idx');
        DB::statement('DROP INDEX IF EXISTS offers_active_product_price_idx');
    }
};
