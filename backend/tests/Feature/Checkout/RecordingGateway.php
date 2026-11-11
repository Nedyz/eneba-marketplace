<?php

namespace Tests\Feature\Checkout;

use App\Payments\Card;
use App\Payments\FakePaymentGateway;
use App\Payments\PaymentGateway;
use App\Payments\PaymentResult;
use Closure;
use RuntimeException;

/**
 * Test double around the real fake: counts charges and refunds, and can run a
 * callback in the middle of a charge, to change the world while the payment
 * is "on the network" (clock, other buyers, lazy expiry).
 */
class RecordingGateway implements PaymentGateway
{
    /** @var list<string> idempotency keys, one per call */
    public array $charges = [];

    /** @var list<string> */
    public array $refunds = [];

    public ?Closure $duringCharge = null;

    public bool $failNextCall = false;

    public function __construct(private FakePaymentGateway $inner = new FakePaymentGateway) {}

    public function charge(string $idempotencyKey, int $amountCents, string $currency, Card $card): PaymentResult
    {
        $this->charges[] = $idempotencyKey;
        if ($this->duringCharge) {
            ($this->duringCharge)();
        }
        if ($this->failNextCall) {
            $this->failNextCall = false;
            throw new RuntimeException('Gateway unreachable');
        }

        return $this->inner->charge($idempotencyKey, $amountCents, $currency, $card);
    }

    public function refund(string $reference): void
    {
        $this->refunds[] = $reference;
    }
}
