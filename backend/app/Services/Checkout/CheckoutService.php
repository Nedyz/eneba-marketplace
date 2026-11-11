<?php

namespace App\Services\Checkout;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Payments\Card;
use App\Payments\PaymentGateway;
use App\Payments\PaymentResult;
use App\Services\Cart\CartQuote;
use App\Services\Cart\CartQuoter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Checkout in two phases (PLAN.md "Checkout and key assignment", D9, D10, D34):
 *
 * 1. placeOrder: one transaction that prices the cart, creates a pending order
 *    and reserves its keys for 15 minutes.
 * 2. pay: a short transaction that marks the order `paying` and holds the keys,
 *    the charge with no transaction open, then a short transaction that sells
 *    the keys or releases them.
 */
class CheckoutService
{
    public const RESERVATION_MINUTES = 15;

    /** Minimum time the keys stay held once a payment starts (D10). */
    public const PAYMENT_HOLD_MINUTES = 2;

    public function __construct(
        private CartQuoter $quoter,
        private OrderKeys $keys,
        private OrderExpirer $expirer,
        private PaymentGateway $gateway,
    ) {}

    /**
     * POST /orders.
     *
     * A 409 changes nothing: every check runs inside the transaction and a
     * conflict rolls it back, including the cancellation of the previous order.
     *
     * @param  list<array{offer_id: int, qty: int}>  $lines
     * @param  int  $expectedTotalCents  the total the buyer was shown (D31)
     *
     * @throws CheckoutConflict out_of_stock | price_changed
     */
    public function placeOrder(User $user, array $lines, int $expectedTotalCents): Order
    {
        return DB::transaction(function () use ($user, $lines, $expectedTotalCents) {
            $now = now();

            // One checkout at a time per user: a second request (double click,
            // second tab) waits here until the first commits, then replaces it.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            // One pending order per user (D34). Its keys are released inside
            // this transaction, so the quote below already counts them as free:
            // buying the last key again after going back to the cart works.
            $this->cancelPendingOrders($user);

            $quote = $this->quoter->quote($lines, $now);
            if (! $quote->canCheckout()) {
                throw new CheckoutConflict(CheckoutConflict::OUT_OF_STOCK,
                    'Some items are no longer available in the quantity you chose.', $quote);
            }
            if ($quote->totalCents() !== $expectedTotalCents) {
                throw new CheckoutConflict(CheckoutConflict::PRICE_CHANGED,
                    'Prices changed since you last saw your cart. Check the new total.', $quote);
            }

            try {
                // Nested transaction = savepoint: if a key was taken between
                // the quote and the reservation, only this part rolls back, and
                // the fresh quote below is computed in the same state the next
                // attempt will start from.
                return DB::transaction(fn () => $this->createAndReserve($user, $quote, $now));
            } catch (KeysTaken) {
                throw new CheckoutConflict(CheckoutConflict::OUT_OF_STOCK,
                    'Another buyer just reserved the last keys of an item in your cart.',
                    $this->quoter->quote($lines, $now));
            }
        });
    }

    /**
     * POST /orders/{id}/pay. Returns the paid order (also when it was already
     * paid: a repeated request gets the same result and no second charge).
     *
     * @throws CheckoutConflict reservation_expired | reservation_lost | payment_in_progress | order_not_payable
     * @throws PaymentDeclined
     */
    public function pay(Order $order, Card $card): Order
    {
        $started = $this->startPayment($order->id);
        if ($started->status === OrderStatus::Paid) {
            return $started;
        }

        // No transaction and no lock is open during the network call (D9).
        try {
            $result = $this->gateway->charge(
                // Unique per order, even if ids were ever reused after a reset.
                "order-{$started->id}-{$started->created_at->getTimestamp()}",
                $started->total_cents,
                $started->currency,
                $card,
            );
        } catch (Throwable $e) {
            // Outcome unknown (e.g. network). Back to pending, keys still held:
            // a retry sends the same idempotency key, so it can never charge twice.
            $this->abandonPayment($started->id);
            throw $e;
        }

        return $this->finishPayment($started->id, $result);
    }

    private function createAndReserve(User $user, CartQuote $quote, CarbonInterface $now): Order
    {
        $expiresAt = $now->copy()->addMinutes(self::RESERVATION_MINUTES);

        $order = Order::create([
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
            'total_cents' => $quote->totalCents(),
            'currency' => 'EUR',
            'expires_at' => $expiresAt,
        ]);

        foreach ($quote->lines as $line) {
            // Price and names copied now: history never changes afterwards.
            $item = $order->items()->create([
                'offer_id' => $line->offerId,
                'quantity' => $line->qty,
                'unit_price_cents' => $line->offer->price_cents,
                'product_name' => $line->offer->product->name,
                'seller_name' => $line->offer->seller->name,
            ]);
            $this->keys->reserve($item, $line->qty, $expiresAt, $now);
        }

        return $order;
    }

    /** The caller holds the user's row lock. A `paying` order is never cancelled. */
    private function cancelPendingOrders(User $user): void
    {
        $pending = Order::query()
            ->where('user_id', $user->id)
            ->where('status', OrderStatus::Pending)
            ->lockForUpdate()
            ->get();

        foreach ($pending as $order) {
            $this->keys->release($order);
            $order->update(['status' => OrderStatus::Cancelled]);
        }
    }

    /**
     * Transaction A: lock the order row (waiting, not skipping: a concurrent
     * pay request must see the outcome of this one) and decide.
     */
    private function startPayment(int $orderId): Order
    {
        // Conflicts are returned, not thrown, inside the closure: throwing
        // would roll back the expiry or release that must be committed.
        $outcome = DB::transaction(function () use ($orderId) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            switch ($order->status) {
                case OrderStatus::Paid:
                    return $order;
                case OrderStatus::Paying:
                    return new CheckoutConflict(CheckoutConflict::PAYMENT_IN_PROGRESS,
                        'This order is already being paid.', order: $order);
                case OrderStatus::Pending:
                    break;
                default:
                    return new CheckoutConflict(CheckoutConflict::ORDER_NOT_PAYABLE,
                        "This order can no longer be paid (it is {$order->status->value}).", order: $order);
            }

            if ($this->expirer->expireLocked($order)) {
                return new CheckoutConflict(CheckoutConflict::RESERVATION_EXPIRED,
                    'Your reservation expired. Nothing was charged.', order: $order);
            }

            // Should never happen while the order is pending and in time (its
            // keys cannot be taken before expires_at); checked anyway, before
            // any money moves.
            if ($this->keys->lockHeld($order) !== $this->keyCount($order)) {
                $this->keys->release($order);
                $order->update(['status' => OrderStatus::Failed]);

                return new CheckoutConflict(CheckoutConflict::RESERVATION_LOST,
                    'Some of your reserved keys are no longer available. Nothing was charged.', order: $order);
            }

            // Payment hold: at least 2 minutes from now, so the reservation
            // cannot run out while the card is being charged.
            $hold = now()->addMinutes(self::PAYMENT_HOLD_MINUTES)->max($order->expires_at);
            $this->keys->extend($order, $hold);
            $order->update(['status' => OrderStatus::Paying, 'expires_at' => $hold]);

            return $order;
        });

        if ($outcome instanceof CheckoutConflict) {
            throw $outcome;
        }

        return $outcome;
    }

    /** Transaction B: record the gateway's answer. */
    private function finishPayment(int $orderId, PaymentResult $result): Order
    {
        [$order, $refund, $error] = DB::transaction(function () use ($orderId, $result) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            // The hold ran out during a very slow charge and the order was
            // already failed by lazy expiry: its keys may be gone.
            if ($order->status !== OrderStatus::Paying) {
                return [$order, $result->reference, new CheckoutConflict(CheckoutConflict::RESERVATION_EXPIRED,
                    'The payment took too long and your reservation ran out. Any charge was refunded.', order: $order)];
            }

            if (! $result->succeeded) {
                $this->keys->release($order);
                $order->update(['status' => OrderStatus::Failed]);

                return [$order, null, new PaymentDeclined($order)];
            }

            // Safety net (PLAN.md 2.3): keys still ours? Only false if the hold
            // ran out and another buyer took one before lazy expiry noticed.
            if ($this->keys->lockHeld($order) !== $this->keyCount($order)) {
                $this->keys->release($order);
                $order->update(['status' => OrderStatus::Failed]);

                return [$order, $result->reference, new CheckoutConflict(CheckoutConflict::RESERVATION_LOST,
                    'Some of your reserved keys are no longer available. Your payment was refunded.', order: $order)];
            }

            $this->keys->markSold($order, now());
            $order->update([
                'status' => OrderStatus::Paid,
                'payment_reference' => $result->reference,
                'paid_at' => now(),
            ]);

            return [$order, null, null];
        });

        // Network call again, so after the commit.
        if ($refund !== null) {
            $this->gateway->refund($refund);
        }
        if ($error !== null) {
            throw $error;
        }

        return $order;
    }

    /** The charge failed with an exception: allow a retry while the keys are held. */
    private function abandonPayment(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status === OrderStatus::Paying) {
                $order->update(['status' => OrderStatus::Pending]);
            }
        });
    }

    private function keyCount(Order $order): int
    {
        return (int) $order->items()->sum('quantity');
    }
}
