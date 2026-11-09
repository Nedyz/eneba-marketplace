<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductKey;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductKey> */
class ProductKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            // Goes through the model's `encrypted` cast, so it is stored encrypted.
            'code' => Str::upper(Str::random(5).'-'.Str::random(5).'-'.Str::random(5)),
            'status' => 'available',
        ];
    }

    /**
     * Sold to a paid order. Applied after creation: the offer is only known once
     * relationships like ->for($offer) are resolved, and the CHECK constraint
     * requires an order item for a sold key.
     */
    public function sold(): static
    {
        return $this->afterCreating(fn (ProductKey $key) => $key->update([
            'status' => 'sold',
            'sold_at' => now(),
            'order_item_id' => $this->orderItemFor($key->offer_id, 'paid')->id,
        ]));
    }

    /** Reserved by a pending order until $until (in the past = expired reservation). */
    public function reserved(DateTimeInterface $until): static
    {
        return $this->afterCreating(fn (ProductKey $key) => $key->update([
            'status' => 'reserved',
            'reserved_until' => $until,
            'order_item_id' => $this->orderItemFor($key->offer_id, 'pending')->id,
        ]));
    }

    private function orderItemFor(int $offerId, string $status): OrderItem
    {
        $offer = Offer::with('product', 'seller')->findOrFail($offerId);
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => $status,
            'total_cents' => $offer->price_cents,
            'expires_at' => now()->addMinutes(15),
            'paid_at' => $status === 'paid' ? now() : null,
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'offer_id' => $offer->id,
            'quantity' => 1,
            'unit_price_cents' => $offer->price_cents,
            'product_name' => $offer->product->name,
            'seller_name' => $offer->seller->name,
        ]);
    }
}
