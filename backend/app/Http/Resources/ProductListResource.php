<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Discount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product card in the store (list view). Like a response DTO in .NET:
 * the database shape never leaks into the API by accident.
 *
 * @mixin Product
 */
class ProductListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'image_url' => $this->image_url,
            'release_date' => $this->release_date?->toDateString(),
            'platform' => ['slug' => $this->platform->slug, 'name' => $this->platform->name],
            'region' => ['slug' => $this->region->slug, 'name' => $this->region->name],
            'price' => $this->price(),
        ];
    }

    /** @return array{from_cents: int, original_cents: int|null, discount_percent: int|null, currency: string}|null */
    private function price(): ?array
    {
        // Columns added by ProductQuery's lateral join; null = nothing in stock.
        if ($this->from_price_cents === null) {
            return null;
        }

        $from = (int) $this->from_price_cents;
        $original = $this->from_original_price_cents !== null ? (int) $this->from_original_price_cents : null;

        return [
            'from_cents' => $from,
            'original_cents' => $original,
            'discount_percent' => Discount::percent($from, $original),
            'currency' => 'EUR',
        ];
    }
}
