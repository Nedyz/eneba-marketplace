<?php

namespace App\Services\Catalog;

use App\Models\Offer;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\Region;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the store query: products with their "from" price, filtered and sorted.
 *
 * From price = cheapest ACTIVE offer that has at least one SELLABLE key
 * (ProductKey::scopeSellable). It is computed on every read, not stored, because
 * stock changes over time without any write: an expired reservation becomes
 * sellable again at reserved_until (D10). A stored price would be stale until
 * something touched the product. See DECISIONS.md D23.
 */
class ProductQuery
{
    /** Alias of the lateral subquery holding the cheapest sellable offer. */
    private const BEST = 'best_offer';

    /**
     * Products with `from_price_cents` and `from_original_price_cents` columns
     * (both null when nothing is in stock), filters and sort applied.
     *
     * @return Builder<Product>
     */
    public function build(ProductFilters $filters, CarbonInterface $now): Builder
    {
        $query = $this->withFromPrice(Product::query(), $now)
            ->with(['platform', 'region']); // eager load: 3 queries per page, not 1 + 2N

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters->sort);

        return $query;
    }

    /**
     * Number of products matching the filters, for pagination.
     *
     * paginate() would count with the full query, computing the from price of
     * every product (the most expensive part). The count only depends on that
     * price when a price filter is set, so otherwise the join is left out
     * (benchmark: 58 ms -> a few ms at 50k products, DECISIONS.md D23).
     */
    public function count(ProductFilters $filters, CarbonInterface $now): int
    {
        $query = Product::query();
        if ($filters->priceMinCents !== null || $filters->priceMaxCents !== null) {
            $this->withFromPrice($query, $now);
        }
        $this->applyFilters($query, $filters);

        return $query->count();
    }

    /**
     * LEFT JOIN LATERAL = "for each product row, run this subquery" (like a
     * correlated Select(...).FirstOrDefault() in LINQ). LATERAL rather than
     * GROUP BY + MIN(price): we need the original price of that same offer too.
     * LEFT, so products without stock stay in the list with null prices.
     *
     * Per product, Postgres walks offers_active_product_price_idx cheapest first
     * and stops at the first offer whose EXISTS finds a key (partial index
     * product_keys_unsold_offer_idx, which skips sold keys).
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function withFromPrice(Builder $query, CarbonInterface $now): Builder
    {
        $cheapestSellableOffer = Offer::query()
            ->select('offers.price_cents', 'offers.original_price_cents')
            ->whereColumn('offers.product_id', 'products.id')
            // Literal condition, not a bound `= ?` parameter: Postgres uses the partial
            // index offers_active_product_price_idx (WHERE is_active) only if it can prove
            // the query implies the index predicate, which a literal always allows.
            ->whereRaw('offers.is_active')
            ->whereExists(
                ProductKey::query()
                    ->select(DB::raw(1))
                    ->whereColumn('product_keys.offer_id', 'offers.id')
                    ->sellable($now)
            )
            ->orderBy('offers.price_cents')
            ->orderBy('offers.id') // deterministic when two sellers have the same price
            ->limit(1);

        return $query
            ->select('products.*')
            ->addSelect([
                self::BEST.'.price_cents as from_price_cents',
                self::BEST.'.original_price_cents as from_original_price_cents',
            ])
            ->leftJoinLateral($cheapestSellableOffer, self::BEST);
    }

    /** @param  Builder<Product>  $query */
    private function applyFilters(Builder $query, ProductFilters $filters): void
    {
        // Several platforms = OR (whereIn); platform + region = AND (two wheres).
        // Unknown slugs match nothing, so a stale shared URL shows an empty list, not an error.
        if ($filters->platforms !== []) {
            $query->whereIn('products.platform_id', Platform::query()->select('id')->whereIn('slug', $filters->platforms));
        }
        if ($filters->regions !== []) {
            $query->whereIn('products.region_id', Region::query()->select('id')->whereIn('slug', $filters->regions));
        }

        // Compared with the from price. NULL (out of stock) never satisfies >= or <=,
        // so out-of-stock products drop out as soon as a price bound is set.
        if ($filters->priceMinCents !== null) {
            $query->where(self::BEST.'.price_cents', '>=', $filters->priceMinCents);
        }
        if ($filters->priceMaxCents !== null) {
            $query->where(self::BEST.'.price_cents', '<=', $filters->priceMaxCents);
        }
    }

    /**
     * Every sort ends with products.id: with offset pagination (D13), rows that tie
     * on the sort key could otherwise swap between two page requests and a product
     * would show up twice or never. NULLS LAST explicitly, because PostgreSQL puts
     * NULLs FIRST on DESC: out-of-stock products and products without a release
     * date always go to the end.
     *
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, ProductSort $sort): void
    {
        match ($sort) {
            ProductSort::Newest => $query->orderByRaw('products.release_date DESC NULLS LAST'),
            ProductSort::PriceAsc => $query->orderByRaw(self::BEST.'.price_cents ASC NULLS LAST'),
            ProductSort::PriceDesc => $query->orderByRaw(self::BEST.'.price_cents DESC NULLS LAST'),
            ProductSort::Name => $query->orderBy('products.name'),
        };

        $query->orderBy('products.id');
    }
}
