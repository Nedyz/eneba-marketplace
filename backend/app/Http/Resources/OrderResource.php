<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\ProductKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order, for its owner only (the controller checks). Keys are included
 * only once the order is paid; until then `keys` is null.
 *
 * `seconds_left` is computed by the server, so the payment page's timer does
 * not depend on the buyer's clock being right; null once no keys are held.
 *
 * @property Order $resource
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $order->loadMissing(['items.offer.product.platform', 'items.offer.product.region', 'items.keys']);
        $holdsKeys = $order->status->holdsKeys();

        return [
            'id' => $order->id,
            'status' => $order->status->value,
            'total_cents' => $order->total_cents,
            'currency' => $order->currency,
            'created_at' => $order->created_at->toIso8601String(),
            'expires_at' => $holdsKeys ? $order->expires_at->toIso8601String() : null,
            'seconds_left' => $holdsKeys ? max(0, (int) floor(now()->diffInSeconds($order->expires_at))) : null,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'items' => $order->items->sortBy('id')->values()->map(fn (OrderItem $item) => [
                'offer_id' => $item->offer_id,
                // Names as they were when bought; the slug links to today's page.
                'product_name' => $item->product_name,
                'seller_name' => $item->seller_name,
                'product_slug' => $item->offer->product->slug,
                'platform' => $item->offer->product->platform->name,
                'region' => $item->offer->product->region->name,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'line_total_cents' => $item->unit_price_cents * $item->quantity,
                // Decrypted here, explicitly (ProductKey hides `code` otherwise, D9).
                'keys' => $order->status === OrderStatus::Paid
                    ? $item->keys->where('status', 'sold')->sortBy('id')->values()->map(fn (ProductKey $key) => $key->code)->all()
                    : null,
            ])->all(),
        ];
    }
}
