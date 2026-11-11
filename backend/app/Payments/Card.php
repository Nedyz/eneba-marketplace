<?php

namespace App\Payments;

/**
 * Card details for one charge. Only ever held in memory for the length of the
 * request: never stored, never logged (D34). With a real provider the card
 * would not reach this server at all (Stripe Elements sends it to Stripe and
 * the server only sees a token).
 */
final readonly class Card
{
    /**
     * @param  string  $number  digits only
     */
    public function __construct(
        public string $number,
        public int $expiryMonth,
        public int $expiryYear,
        public string $cvc,
    ) {}

    /** The only part of a card number that may ever be shown or logged. */
    public function last4(): string
    {
        return substr($this->number, -4);
    }

    /** Hides the number from var_dump(), dd() and exception traces. */
    public function __debugInfo(): array
    {
        return ['number' => '**** '.$this->last4()];
    }
}
