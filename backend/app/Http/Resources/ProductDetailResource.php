<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;

/**
 * The product page: the store card's fields (same `price` block, same code)
 * plus the description and every active offer, already in display order
 * (see ProductDetails).
 *
 * @mixin Product
 */
class ProductDetailResource extends ProductListResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'offers' => OfferResource::collection($this->offers),
        ];
    }
}
