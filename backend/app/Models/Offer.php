<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A seller's price for a product. Money in integer cents (D12).
 * A deal = original_price_cents set (the database guarantees it is above price_cents).
 */
#[Fillable(['product_id', 'seller_id', 'price_cents', 'original_price_cents', 'is_active'])]
class Offer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'original_price_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return HasMany<ProductKey, $this> */
    public function keys(): HasMany
    {
        return $this->hasMany(ProductKey::class);
    }
}
