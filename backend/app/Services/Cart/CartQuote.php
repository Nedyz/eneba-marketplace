<?php

namespace App\Services\Cart;

/**
 * The result of quoting a cart: the lines in the order they were requested,
 * the total of the lines that can be bought, and whether checkout may start.
 */
final readonly class CartQuote
{
    /** @param  list<CartQuoteLine>  $lines */
    public function __construct(public array $lines) {}

    /**
     * Sum of the available lines only. Checkout is blocked unless every line is
     * available (canCheckout), so whenever checkout is possible this is exactly
     * the amount it will charge.
     */
    public function totalCents(): int
    {
        return array_sum(array_map(fn (CartQuoteLine $line) => $line->lineTotalCents() ?? 0, $this->lines));
    }

    /**
     * True only when every line can be bought in full (D31: problem lines block
     * checkout; the cart is never changed automatically, because stock can come
     * back when a reservation expires).
     */
    public function canCheckout(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->status !== CartLineStatus::Available) {
                return false;
            }
        }

        return $this->lines !== [];
    }
}
