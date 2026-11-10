<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Http\Resources\HomeBlockResource;
use App\Http\Resources\ProductListResource;
use App\Services\Home\BestSellers;
use App\Services\Home\Deals;
use App\Services\Home\HomeBanners;
use App\Services\Home\HomeLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Server-driven homepage (D4). GET /home is the layout; every other action is
 * the content of one block type, each from its own service and data source (D3).
 *
 * Contract per block type:
 * - banner:           endpoint returns { data: Banner[] }
 * - product_carousel: endpoint returns { data: ProductSummary[] } (the store card)
 * So a new carousel ("New releases") needs a service, a route and a home_blocks
 * row, and no frontend change.
 */
class HomeController extends Controller
{
    /** GET /api/home */
    public function layout(HomeLayout $layout): JsonResponse
    {
        return response()->json(['blocks' => HomeBlockResource::collection($layout->blocks())]);
    }

    /** GET /api/home/banners */
    public function banners(HomeBanners $banners): AnonymousResourceCollection
    {
        return BannerResource::collection($banners->current(now()));
    }

    /** GET /api/home/best-sellers */
    public function bestSellers(BestSellers $bestSellers): AnonymousResourceCollection
    {
        return ProductListResource::collection($bestSellers->top(now()));
    }

    /** GET /api/home/deals */
    public function deals(Deals $deals): AnonymousResourceCollection
    {
        return ProductListResource::collection($deals->top(now()));
    }
}
