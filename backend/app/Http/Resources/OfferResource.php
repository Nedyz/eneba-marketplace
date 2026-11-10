<?php

namespace App\Http\Resources;

use App\Models\Offer;
use App\Support\Discount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One seller's offer on the product page. Expects `stock` (count of sellable
 * keys) and `seller` loaded by ProductDetails. Key codes are never part of it.
 *
 * @mixin Offer
 */
class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seller' => [
                'id' => $this->seller->id,
                'name' => $this->seller->name,
                // decimal:2 cast gives "4.85" (exact); sent as a number for the UI.
                'rating' => (float) $this->seller->rating,
            ],
            'price_cents' => $this->price_cents,
            'original_price_cents' => $this->original_price_cents,
            'discount_percent' => Discount::percent($this->price_cents, $this->original_price_cents),
            'currency' => 'EUR',
            // Exact number of sellable keys; 0 = sold out (shown dimmed, last).
            // The cart (day 4, session 2) caps quantities with it.
            'stock' => $this->stock,
        ];
    }
}
