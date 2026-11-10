<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchSuggestionsRequest;
use App\Http\Resources\ProductListResource;
use App\Services\Catalog\ProductFilters;
use App\Services\Catalog\ProductQuery;
use App\Services\Catalog\ProductSearch;
use App\Services\Catalog\ProductSort;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchController extends Controller
{
    /** Enough to fill the header dropdown; the "see all results" row links to the store. */
    public const SUGGESTION_LIMIT = 8;

    public function __construct(
        private ProductQuery $products,
        private ProductSearch $search,
    ) {}

    /**
     * GET /api/search/suggestions?q=
     *
     * Same query as the store (match, relevance order, from price), first 8 rows.
     * One row per edition (game + platform + region), as on Eneba: each row links
     * straight to its product page. Reuses the store's product card shape, so the
     * frontend has one product type.
     */
    public function suggestions(SearchSuggestionsRequest $request): AnonymousResourceCollection
    {
        $term = $request->term();
        $filters = new ProductFilters(sort: ProductSort::Relevance, search: $term);

        $products = $this->search->withThreshold($term, fn () => $this->products
            ->build($filters, now())
            ->limit(self::SUGGESTION_LIMIT)
            ->get());

        return ProductListResource::collection($products);
    }
}
