<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    use HasFactory;

    /**
     * Keys that can be sold right now: available, or reserved by a reservation
     * that has already expired (D10: expired reservations count as stock without
     * any cleanup job). The single definition of "in stock", used by the store's
     * from price and (day 5) by the checkout reserve query, so they never disagree.
     *
     * $now is passed in from PHP instead of using SQL now(), so tests can move
     * the clock with Carbon::setTestNow().
     *
     * @param  Builder<ProductKey>  $query
     */
    public function scopeSellable(Builder $query, CarbonInterface $now): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('product_keys.status', 'available')
            ->orWhere(fn (Builder $q) => $q
                ->where('product_keys.status', 'reserved')
                ->where('product_keys.reserved_until', '<', $now)));
    }

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
