<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Offers start WITHOUT keys (= out of stock). Use withKeys() for stock, so every
 * test states explicitly how much stock it relies on.
 *
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'seller_id' => Seller::factory(),
            'price_cents' => fake()->numberBetween(500, 6000),
            'original_price_cents' => null,
            'is_active' => true,
        ];
    }

    public function price(int $cents, ?int $originalCents = null): static
    {
        return $this->state(['price_cents' => $cents, 'original_price_cents' => $originalCents]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /** Adds $count available keys. */
    public function withKeys(int $count = 1): static
    {
        return $this->has(ProductKey::factory()->count($count), 'keys');
    }
}
