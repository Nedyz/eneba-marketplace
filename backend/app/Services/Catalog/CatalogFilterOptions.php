<?php

namespace App\Services\Catalog;

use App\Models\Platform;
use App\Models\Product;
use App\Models\Region;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Data for the store's filter panel (GET /products/filters).
 *
 * The options are the same for every request (not narrowed by the filters
 * already selected): simpler, and enough for a small catalog.
 */
class CatalogFilterOptions
{
    public function __construct(private ProductQuery $products) {}

    /**
     * @return array{platforms: list<array{slug: string, name: string}>, regions: list<array{slug: string, name: string}>, price: array{min_cents: int|null, max_cents: int|null}}
     */
    public function get(CarbonInterface $now): array
    {
        // Price bounds for the slider: min/max of the from price over products in stock,
        // computed with the exact same join as the list, so the slider range always
        // matches what the price filter can find.
        $range = DB::query()
            ->fromSub($this->products->withFromPrice(Product::query(), $now)->toBase(), 'p')
            ->selectRaw('min(from_price_cents) as min_cents, max(from_price_cents) as max_cents')
            ->first();

        return [
            'platforms' => $this->optionsWithProducts(Platform::class),
            'regions' => $this->optionsWithProducts(Region::class),
            'price' => [
                'min_cents' => $range->min_cents !== null ? (int) $range->min_cents : null,
                'max_cents' => $range->max_cents !== null ? (int) $range->max_cents : null,
            ],
        ];
    }

    /**
     * Only options that have at least one product, so the panel never offers a
     * filter that always returns nothing.
     *
     * @param  class-string<Platform|Region>  $model
     * @return list<array{slug: string, name: string}>
     */
    private function optionsWithProducts(string $model): array
    {
        return $model::query()
            ->whereHas('products')
            ->orderBy('name')
            ->get(['slug', 'name'])
            ->map(fn ($o) => ['slug' => $o->slug, 'name' => $o->name])
            ->all();
    }
}
