<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * pending (keys reserved until expires_at) -> paid | failed | expired.
 */
#[Fillable(['user_id', 'status', 'total_cents', 'currency', 'expires_at', 'payment_reference', 'paid_at'])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'total_cents' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
