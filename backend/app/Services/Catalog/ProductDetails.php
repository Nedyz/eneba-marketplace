<?php

namespace App\Services\Catalog;

use App\Models\Offer;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The product page: one product with every active offer, for comparing sellers
 * (DECISIONS.md D30).
 *
 * Offer order:
 *   1. offers with stock before sold-out offers (a sold-out offer never sits
 *      above one you can buy, even when it is cheaper),
 *   2. price, cheapest first,
 *   3. offers.id, the same tie-break as the store's from price
 *      (ProductQuery::withFromPrice), so the first offer here is exactly the
 *      offer behind the store card, including its original price and discount.
 *
 * Sold-out offers (no sellable key) stay in the list, last, with stock 0: like
 * sold-out products in the store (D24), and their stock can come back without
 * any write when a reservation expires (D10). Inactive offers are left out: the
 * seller withdrew them, they are not "temporarily unavailable".
 */
class ProductDetails
{
    public function __construct(private ProductQuery $products) {}

    /**
     * Null when no product has this slug. $now is passed in so the header price
     * and the per-offer stock are computed at the same instant and agree.
     */
    public function find(string $slug, CarbonInterface $now): ?Product
    {
        // The header price comes from the store's own from-price query, not from a
        // second implementation here, so the product page and the store card
        // cannot disagree about it.
        $product = $this->products
            ->withFromPrice(Product::query(), $now)
            ->with(['platform', 'region'])
            ->where('products.slug', $slug)
            ->first();

        if ($product === null) {
            return null;
        }

        $product->setRelation('offers', $this->offers($product, $now));

        return $product;
    }

    /**
     * One query for all offers with their stock, plus one for their sellers.
     *
     * `stock` = number of sellable keys, a correlated COUNT(*) per offer
     * (withCount, like a .Select(o => o.Keys.Count(...)) projection in LINQ),
     * using the same sellable scope as the store and checkout.
     *
     * The three-step order is applied in PHP: Postgres cannot use the `stock`
     * alias inside an ORDER BY expression, so SQL would need the count subquery
     * written twice. A product has at most one offer per seller (unique index),
     * so this sorts a handful of rows.
     *
     * @return Collection<int, Offer>
     */
    private function offers(Product $product, CarbonInterface $now): Collection
    {
        return $product->offers()
            ->where('offers.is_active', true)
            ->with('seller')
            ->withCount(['keys as stock' => fn (Builder $keys) => $keys->sellable($now)])
            ->get()
            ->each(fn (Offer $offer) => $offer->stock = (int) $offer->stock)
            // Arrays compare element by element (like a tuple comparison in C#):
            // false < true, so in-stock offers (sold out = false) come first.
            ->sort(fn (Offer $a, Offer $b) => [$a->stock === 0, $a->price_cents, $a->id]
                <=> [$b->stock === 0, $b->price_cents, $b->id])
            ->values();
    }
}
