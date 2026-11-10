<?php

namespace App\Http\Resources;

use App\Services\Cart\CartQuoteLine;
use App\Support\Discount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One quoted cart line. Every field is always present (null when unknown), so
 * the frontend type has one shape for all four statuses.
 *
 * - available / insufficient_stock / sold_out: price, product and seller set.
 * - unavailable: no price (the offer cannot be bought at any price), stock 0;
 *   product and seller are still sent when the offer row exists (withdrawn by
 *   the seller), and are null when it was deleted.
 *
 * @property CartQuoteLine $resource
 */
class CartQuoteLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $line = $this->resource;
        $offer = $line->offer;
        $unitPrice = $line->unitPriceCents();
        $original = $unitPrice === null ? null : $offer->original_price_cents;

        return [
            'offer_id' => $line->offerId,
            'qty' => $line->qty,
            'status' => $line->status->value,
            'stock' => $line->stock,
            'unit_price_cents' => $unitPrice,
            'original_price_cents' => $original,
            'discount_percent' => $unitPrice === null ? null : Discount::percent($unitPrice, $original),
            'line_total_cents' => $line->lineTotalCents(),
            'product' => $offer === null ? null : [
                'slug' => $offer->product->slug,
                'name' => $offer->product->name,
                'image_url' => $offer->product->image_url,
                'platform' => ['slug' => $offer->product->platform->slug, 'name' => $offer->product->platform->name],
                'region' => ['slug' => $offer->product->region->slug, 'name' => $offer->product->region->name],
            ],
            'seller' => $offer === null ? null : [
                'id' => $offer->seller->id,
                'name' => $offer->seller->name,
                'rating' => (float) $offer->seller->rating,
            ],
        ];
    }
}
