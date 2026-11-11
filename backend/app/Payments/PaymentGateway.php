<?php

namespace App\Payments;

/**
 * The payment provider, behind an interface (D11), like an IPaymentGateway
 * registered in the DI container. FakePaymentGateway is the only
 * implementation; Stripe test mode would be a second one.
 *
 * Called outside any database transaction: a network call must never run
 * while row locks are held (D9).
 */
interface PaymentGateway
{
    /**
     * Charges the card.
     *
     * $idempotencyKey: the same key always gets the result of the first call,
     * and the card is charged at most once per key (Stripe's Idempotency-Key
     * header works the same way). Checkout passes one key per order.
     */
    public function charge(string $idempotencyKey, int $amountCents, string $currency, Card $card): PaymentResult;

    /** Gives back a successful charge in full. */
    public function refund(string $reference): void;
}
