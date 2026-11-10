<?php

namespace App\Services\Home;

use App\Models\Product;
use App\Services\Catalog\ProductQuery;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Deals block: products whose CARD price is discounted, largest discount first.
 *
 * Ranked by the from-price offer (cheapest sellable offer, ProductQuery), not by
 * the most discounted offer of the product: otherwise a product could be here
 * for a -60% offer while its card shows another seller's cheaper, undiscounted
 * price. Same reasoning as D30: the card and what it links to must agree.
 * A discounted offer that is sold out is not the from-price offer, so it never
 * puts a product in this block.
 */
class Deals
{
    public const LIMIT = 12;

    public function __construct(private ProductQuery $products) {}

    /** @return Collection<int, Product> */
    public function top(CarbonInterface $now): Collection
    {
        return $this->products->withFromPrice(Product::query(), $now)
            ->whereNotNull('best_offer.original_price_cents') // also excludes sold out (whole row null)
            // Price / original price, smallest first = largest real discount first.
            // ::numeric, otherwise integer division would round every ratio to 0.
            ->orderByRaw('best_offer.price_cents::numeric / best_offer.original_price_cents ASC')
            ->orderBy('products.id')
            ->limit(self::LIMIT)
            ->with(['platform', 'region'])
            ->get();
    }
}
