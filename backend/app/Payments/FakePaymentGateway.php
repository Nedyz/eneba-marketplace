<?php

namespace App\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Simulated payments (D11): no money moves. The outcome depends only on the
 * card number, like Stripe's test cards. Any other number is rejected earlier,
 * by PayOrderRequest, so a real card number is never "charged" here.
 *
 * Idempotency is kept in the cache for a day: the first result per key is
 * stored and returned again for every later call with the same key.
 */
class FakePaymentGateway implements PaymentGateway
{
    public const SUCCESS_CARD = '4242424242424242';

    public const DECLINED_CARD = '4000000000000002';

    public const TEST_CARDS = [self::SUCCESS_CARD, self::DECLINED_CARD];

    public function charge(string $idempotencyKey, int $amountCents, string $currency, Card $card): PaymentResult
    {
        // Cache::remember runs the closure only when the key is not stored yet.
        // Not atomic on its own; checkout never calls charge twice concurrently
        // for one order anyway (the order is `paying` first, D34). This is the
        // second line of defence, as with a real provider.
        $stored = Cache::remember("fake-payment:{$idempotencyKey}", now()->addDay(), function () use ($idempotencyKey, $amountCents, $currency, $card) {
            if ($card->number !== self::SUCCESS_CARD) {
                Log::info('fake_payment.declined', ['idempotency_key' => $idempotencyKey, 'card' => '**** '.$card->last4()]);

                return ['succeeded' => false, 'reason' => 'card_declined'];
            }

            $reference = 'fake_'.Str::lower(Str::random(24));
            // One line per real charge: benchmarks/checkout/race.php counts them.
            Log::info('fake_payment.charged', [
                'idempotency_key' => $idempotencyKey,
                'amount_cents' => $amountCents,
                'currency' => $currency,
                'reference' => $reference,
                'card' => '**** '.$card->last4(),
            ]);

            return ['succeeded' => true, 'reference' => $reference];
        });

        return $stored['succeeded']
            ? PaymentResult::succeeded($stored['reference'])
            : PaymentResult::declined($stored['reason']);
    }

    public function refund(string $reference): void
    {
        Log::info('fake_payment.refunded', ['reference' => $reference]);
    }
}
