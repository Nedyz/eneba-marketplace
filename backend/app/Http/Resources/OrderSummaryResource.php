<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order in the order history (D35). Deliberately without keys: a page of
 * history should not decrypt and send every key the buyer ever bought. The
 * keys are on the order's own page (OrderResource).
 *
 * The controller eager-loads items.offer.product.{platform,region}, so a page
 * costs the same number of queries whatever its size.
 *
 * @property Order $resource
 */
class OrderSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            'id' => $order->id,
            'status' => $order->status->value,
            'total_cents' => $order->total_cents,
            'currency' => $order->currency,
            'created_at' => $order->created_at->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'items' => $order->items->sortBy('id')->values()->map(fn (OrderItem $item) => [
                'offer_id' => $item->offer_id,
                'product_name' => $item->product_name,
                'product_slug' => $item->offer->product->slug,
                'platform' => $item->offer->product->platform->name,
                'region' => $item->offer->product->region->name,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'line_total_cents' => $item->unit_price_cents * $item->quantity,
            ])->all(),
        ];
    }
}
