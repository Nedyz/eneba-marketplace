<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Price and names are copied at purchase time, so history does not change
 * when the offer or product changes later.
 */
#[Fillable(['order_id', 'offer_id', 'quantity', 'unit_price_cents', 'product_name', 'seller_name'])]
class OrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return HasMany<ProductKey, $this> */
    public function keys(): HasMany
    {
        return $this->hasMany(ProductKey::class);
    }
}
