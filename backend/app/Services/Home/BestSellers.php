<?php

namespace App\Services\Home;

use App\Models\Product;
use App\Services\Catalog\ProductQuery;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Best sellers block: products ranked by units sold in the last 30 days, with
 * the remaining places filled by all-time best sellers (D32).
 *
 * Why the fill: the demo orders are dated relative to the seed time, and
 * production is seeded once (D20). About 30 days after that, no seeded order is
 * inside the window, and a pure 30-day ranking would return nothing.
 *
 * One aggregate per product computes both numbers, and the sort does the rest:
 *   recent_units DESC  -> anything sold in the window comes first, by window sales
 *   total_units DESC   -> then all-time sales (also breaks ties inside the window)
 *   products.id        -> deterministic
 * A product with recent sales therefore always ranks above one without, so the
 * fill can only use places the 30-day ranking left empty.
 *
 * Sold-out products are left out (Nedas's choice): this block is there to lead
 * to a purchase. The price is the store card's from price (ProductQuery), so a
 * card here is exactly the card in the store.
 */
class BestSellers
{
    public const LIMIT = 12;

    public const WINDOW_DAYS = 30;

    public function __construct(private ProductQuery $products) {}

    /** @return Collection<int, Product> */
    public function top(CarbonInterface $now): Collection
    {
        // Units sold per product (summed over all its offers), paid orders only.
        // Pending, failed and expired orders sold nothing.
        $sales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('offers', 'offers.id', '=', 'order_items.offer_id')
            ->where('orders.status', 'paid')
            ->groupBy('offers.product_id')
            ->select('offers.product_id')
            // FILTER = a conditional SUM in one pass (like Sum(x => cond ? q : 0) in LINQ).
            // NULL when nothing sold in the window, hence the COALESCE.
            ->selectRaw(
                'COALESCE(SUM(order_items.quantity) FILTER (WHERE orders.paid_at >= ?), 0) AS recent_units',
                [$now->copy()->subDays(self::WINDOW_DAYS)],
            )
            ->selectRaw('SUM(order_items.quantity) AS total_units');

        return $this->products->withFromPrice(Product::query(), $now)
            ->joinSub($sales, 'sales', 'sales.product_id', '=', 'products.id') // inner: never sold = not a best seller
            ->whereNotNull('best_offer.price_cents') // null from price = sold out
            ->orderByDesc('sales.recent_units')
            ->orderByDesc('sales.total_units')
            ->orderBy('products.id')
            ->limit(self::LIMIT)
            ->with(['platform', 'region'])
            ->get();
    }
}
