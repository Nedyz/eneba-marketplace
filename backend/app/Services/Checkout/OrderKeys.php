<?php

namespace App\Services\Checkout;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductKey;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every write to product_keys made by checkout, in one place (D9, D34).
 * All methods must run inside a transaction opened by the caller.
 */
class OrderKeys
{
    /**
     * Reserves $qty keys of the offer for the order item, until $until.
     *
     * The SELECT takes row locks with SKIP LOCKED: keys another checkout has
     * locked right now are skipped instead of waited for. Two buyers of the
     * last key therefore never wait on each other: one locks it, the other
     * sees no free key and gets "out of stock" at once. Nobody ever waits, so
     * two multi-item orders cannot deadlock either.
     *
     * The UPDATE repeats the sellable condition and its row count is checked:
     * a second guard, so a key can only change hands if it really was free.
     *
     * @throws KeysTaken fewer than $qty free keys (the caller rolls back)
     */
    public function reserve(OrderItem $item, int $qty, CarbonInterface $until, CarbonInterface $now): void
    {
        $ids = ProductKey::query()
            ->where('offer_id', $item->offer_id)
            ->sellable($now)
            ->orderBy('id')
            ->limit($qty)
            ->lock('for update skip locked')
            ->pluck('id');

        if ($ids->count() < $qty) {
            throw new KeysTaken;
        }

        $updated = ProductKey::query()
            ->whereKey($ids)
            ->sellable($now)
            ->update([
                'status' => 'reserved',
                'reserved_until' => $until,
                'order_item_id' => $item->id,
            ]);

        if ($updated !== $qty) {
            throw new KeysTaken;
        }
    }

    /**
     * Locks the keys still reserved for the order and returns how many there
     * are. Waits (no SKIP LOCKED) if another checkout is taking one of them
     * right now; PostgreSQL then re-checks the row, so a key it relinked to
     * its own order is no longer counted here.
     *
     * A key reserved for the order whose reserved_until has passed still
     * counts: nobody has taken it, because taking it would relink it.
     */
    public function lockHeld(Order $order): int
    {
        return $this->reservedFor($order)->lockForUpdate()->get(['id'])->count();
    }

    /** Moves the order's reservation deadline (the payment hold). */
    public function extend(Order $order, CarbonInterface $until): void
    {
        $this->reservedFor($order)->update(['reserved_until' => $until]);
    }

    /** The order's reserved keys become available to everyone again. */
    public function release(Order $order): void
    {
        $this->reservedFor($order)->update([
            'status' => 'available',
            'reserved_until' => null,
            'order_item_id' => null,
        ]);
    }

    /** The order's reserved keys are sold to it. */
    public function markSold(Order $order, CarbonInterface $now): void
    {
        $this->reservedFor($order)->update([
            'status' => 'sold',
            'reserved_until' => null,
            'sold_at' => $now,
        ]);
    }

    /** @return Builder<ProductKey> */
    private function reservedFor(Order $order): Builder
    {
        return ProductKey::query()
            ->where('status', 'reserved')
            ->whereIn('order_item_id', OrderItem::query()->where('order_id', $order->id)->select('id'));
    }
}
