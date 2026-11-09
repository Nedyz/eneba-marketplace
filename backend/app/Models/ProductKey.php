<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A digital key. `code` is encrypted at rest with APP_KEY (D9): the `encrypted`
 * cast encrypts on save and decrypts on read. Bulk inserts bypass casts, so code
 * that inserts keys without Eloquent must call Crypt::encryptString() itself
 * (same format the cast uses).
 */
#[Fillable(['offer_id', 'code', 'status', 'reserved_until', 'order_item_id', 'sold_at'])]
// Never serialized by accident (e.g. returning a model as JSON); the key reveal
// for paid orders will expose it explicitly.
#[Hidden(['code'])]
class ProductKey extends Model
{
    protected function casts(): array
    {
        return [
            'code' => 'encrypted',
            'reserved_until' => 'datetime',
            'sold_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
