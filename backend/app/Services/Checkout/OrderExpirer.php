<?php

namespace App\Services\Checkout;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lazy expiry (D10): Render's free tier has no cron, so an order past its
 * deadline is moved on when someone reads or pays it. Key stock never depends
 * on this: the reserve query already treats expired reservations as free.
 *
 * - pending past expires_at -> expired (the 15 minutes are over)
 * - paying past expires_at  -> failed  (the payment was interrupted: the
 *   process died between charging and recording the result; the 2-minute
 *   hold is over. With a real provider, ask it by idempotency key instead.)
 *
 * In both cases the keys still reserved for the order are released.
 */
class OrderExpirer
{
    public function __construct(private OrderKeys $keys) {}

    /** For a read of one order: only locks when the order looks due. */
    public function expireIfDue(Order $order): Order
    {
        if (! $this->isDue($order)) {
            return $order;
        }

        return DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->expireLocked($locked);

            return $locked;
        });
    }

    /**
     * Every due order (the `orders:expire` command), or only one user's (before
     * their order history is listed, D35). One short transaction per order;
     * SKIP LOCKED leaves an order alone while a pay request holds it, so the
     * history never waits for a payment in progress: that order is listed as
     * it is, and the pay request settles it itself.
     *
     * @return int number of orders expired or failed
     */
    public function expireAllDue(?User $user = null): int
    {
        $count = 0;
        $ids = Order::query()
            ->when($user, fn (Builder $query) => $query->where('user_id', $user->id))
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::Paying])
            ->where('expires_at', '<', now())
            ->pluck('id');

        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $order = Order::query()->whereKey($id)->lock('for update skip locked')->first();

                return $order && $this->expireLocked($order) ? 1 : 0;
            });
        }

        return $count;
    }

    /**
     * The caller holds the order's row lock. Re-checks, because the status may
     * have changed between the caller's read and the lock.
     *
     * @return bool whether the order was changed
     */
    public function expireLocked(Order $order): bool
    {
        if (! $this->isDue($order)) {
            return false;
        }

        $this->keys->release($order);
        $order->update([
            'status' => $order->status === OrderStatus::Paying ? OrderStatus::Failed : OrderStatus::Expired,
        ]);

        return true;
    }

    /** Strictly after the deadline, like the keys (sellable: reserved_until < now). */
    private function isDue(Order $order): bool
    {
        return $order->status->holdsKeys() && now()->gt($order->expires_at);
    }
}
