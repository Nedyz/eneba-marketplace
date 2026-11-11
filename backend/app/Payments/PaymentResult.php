<?php

namespace App\Payments;

/**
 * What the gateway answered. A decline is a normal result, not an exception;
 * an exception means the outcome is unknown (network failure).
 */
final readonly class PaymentResult
{
    private function __construct(
        public bool $succeeded,
        /** The provider's id for the charge (refunds use it). Null when declined. */
        public ?string $reference,
        public ?string $declineReason,
    ) {}

    public static function succeeded(string $reference): self
    {
        return new self(true, $reference, null);
    }

    public static function declined(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
