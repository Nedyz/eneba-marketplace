<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Resources\ProductListResource;
use App\Services\Catalog\CatalogFilterOptions;
use App\Services\Catalog\ProductQuery;
use App\Services\Catalog\ProductSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Thin controller: validation in ProductIndexRequest, logic in Services/Catalog,
 * response shape in ProductListResource. Services come in through constructor
 * injection, resolved by Laravel's container (like ASP.NET Core DI).
 */
class ProductController extends Controller
{
    public function __construct(
        private ProductQuery $products,
        private CatalogFilterOptions $filterOptions,
        private ProductSearch $search,
    ) {}

    /** GET /api/products */
    public function index(ProductIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->filters();
        $now = now(); // one timestamp for both queries, so they agree on stock

        // With a search term, both queries (count and page) must run inside the
        // transaction that lowers the trigram threshold. See ProductSearch.
        $page = $this->search->withThreshold($filters->search, fn () => $this->products
            ->build($filters, $now)
            // Precomputed total: paginate() would otherwise count with the full
            // (expensive) from-price query. See ProductQuery::count().
            ->paginate(perPage: $request->perPage(), page: $request->page(), total: $this->products->count($filters, $now))
            ->withQueryString()); // pagination links keep the active filters

        return ProductListResource::collection($page);
    }

    /** GET /api/products/filters */
    public function filters(): JsonResponse
    {
        return response()->json($this->filterOptions->get(now()));
    }
}
