<?php

namespace Tests\Feature\Checkout;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductKey;
use App\Models\User;
use App\Payments\Card;
use App\Payments\FakePaymentGateway;
use App\Services\Checkout\CheckoutService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /api/orders/{id}/pay (PLAN.md step 2, D10, D11, D34).
 */
class PayOrderTest extends CheckoutTestCase
{
    // ---------------------------------------------------------------- outcomes

    public function test_success_sells_the_keys_and_returns_them(): void
    {
        $offer = $this->offer(1500, keys: 3);
        $order = $this->placed($offer, 2);
        $codes = $this->reservedKeys($order)->pluck('code')->all();

        $this->pay($order)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paid_at', '2026-11-13T12:00:00+00:00')
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.seconds_left', null)
            ->assertJsonPath('data.items.0.keys', $codes);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertStringStartsWith('fake_', $order->payment_reference);
        $sold = ProductKey::where('status', 'sold')->orderBy('id')->get();
        $this->assertSame($codes, $sold->pluck('code')->all());
        foreach ($sold as $key) {
            $this->assertSame($order->items()->value('id'), $key->order_item_id);
            $this->assertNull($key->reserved_until);
            $this->assertNotNull($key->sold_at);
        }
        $this->assertSame(['available' => 1, 'sold' => 2], $this->keyStatuses($offer));
        $this->assertCount(1, $this->gateway->charges);
    }

    public function test_declined_card_fails_the_order_and_releases_the_keys(): void
    {
        $offer = $this->offer(keys: 2);
        $order = $this->placed($offer, 2);

        $this->pay($order, self::DECLINED)
            ->assertStatus(402)
            ->assertJsonPath('code', 'card_declined')
            ->assertJsonPath('order.status', 'failed')
            ->assertJsonPath('order.items.0.keys', null);

        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
        $this->assertNull($order->fresh()->payment_reference);
        $this->assertSame(['available' => 2], $this->keyStatuses($offer));
        $this->assertSame(0, ProductKey::whereNotNull('order_item_id')->count());
    }

    public function test_after_a_decline_a_new_order_can_buy_the_same_keys(): void
    {
        $offer = $this->offer(keys: 1);
        $this->pay($this->placed($offer), self::DECLINED)->assertStatus(402);

        $this->pay($this->placed($offer))->assertOk();
        $this->assertSame(['sold' => 1], $this->keyStatuses($offer));
    }

    // ---------------------------------------------------------------- double click, retries

    public function test_paying_a_paid_order_again_returns_the_same_result_without_charging(): void
    {
        $order = $this->placed($this->offer());
        $first = $this->pay($order)->assertOk()->json();

        // A different card, even: nothing is charged, the order is just returned.
        $this->pay($order, self::DECLINED)->assertOk()->assertExactJson($first);
        $this->assertCount(1, $this->gateway->charges);
    }

    public function test_an_order_being_paid_cannot_be_paid_again(): void
    {
        // What a second click sees: the first request has set `paying` and is
        // waiting for the gateway.
        $order = $this->placed($this->offer());
        $order->update(['status' => OrderStatus::Paying]);

        $this->pay($order)->assertConflict()
            ->assertJsonPath('code', 'payment_in_progress')
            ->assertJsonPath('order.status', 'paying');
        $this->assertSame([], $this->gateway->charges);
    }

    public function test_the_order_is_paying_while_the_gateway_is_called(): void
    {
        $order = $this->placed($this->offer());
        $seen = null;
        $this->gateway->duringCharge = function () use ($order, &$seen) {
            $seen = $order->fresh()->status;
        };

        $this->pay($order)->assertOk();

        $this->assertSame(OrderStatus::Paying, $seen);
    }

    public function test_a_gateway_error_leaves_the_order_payable_and_the_retry_charges_once(): void
    {
        $offer = $this->offer();
        $order = $this->placed($offer);
        $this->gateway->failNextCall = true;

        $this->pay($order)->assertServerError();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertCount(1, $this->reservedKeys($order));

        $this->pay($order)->assertOk();
        // Both attempts used the same idempotency key.
        $this->assertCount(2, $this->gateway->charges);
        $this->assertCount(1, array_unique($this->gateway->charges));
    }

    // ---------------------------------------------------------------- expiry and the payment hold

    public function test_paying_after_the_reservation_expired(): void
    {
        $offer = $this->offer(keys: 1);
        $order = $this->placed($offer);
        Carbon::setTestNow(now()->addMinutes(15)->addSecond());

        $this->pay($order)->assertConflict()
            ->assertJsonPath('code', 'reservation_expired')
            ->assertJsonPath('order.status', 'expired');

        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);
        $this->assertSame(['available' => 1], $this->keyStatuses($offer));
        $this->assertSame([], $this->gateway->charges);
    }

    public function test_paying_at_the_last_second_still_works(): void
    {
        // Same boundary as the keys: reserved_until < now makes a key free,
        // so at exactly expires_at it is still ours.
        $order = $this->placed($this->offer());
        Carbon::setTestNow(now()->addMinutes(15));

        $this->pay($order)->assertOk();
    }

    public function test_the_payment_hold_extends_a_reservation_about_to_run_out(): void
    {
        $order = $this->placed($this->offer());
        Carbon::setTestNow('2026-11-13 12:14:30'); // 30 s left
        $during = null;
        $this->gateway->duringCharge = function () use ($order, &$during) {
            $during = [$order->fresh()->expires_at->toDateTimeString(), $this->reservedKeys($order)->first()->reserved_until->toDateTimeString()];
        };

        $this->pay($order)->assertOk();

        $this->assertSame(['2026-11-13 12:16:30', '2026-11-13 12:16:30'], $during);
    }

    public function test_the_payment_hold_never_shortens_a_reservation(): void
    {
        $order = $this->placed($this->offer());
        $during = null;
        $this->gateway->duringCharge = function () use ($order, &$during) {
            $during = $this->reservedKeys($order)->first()->reserved_until->toDateTimeString();
        };

        $this->pay($order)->assertOk();

        $this->assertSame('2026-11-13 12:15:00', $during);
    }

    public function test_keys_taken_during_a_very_slow_payment_are_refunded(): void
    {
        // Safety net: the hold ran out while the gateway was busy, and another
        // buyer took the key before anything marked this order expired.
        $offer = $this->offer(keys: 1);
        $order = $this->placed($offer);
        $this->gateway->duringCharge = function () use ($offer) {
            // 20 minutes later the hold is over and the key counts as free.
            Carbon::setTestNow(now()->addMinutes(20));
            app(CheckoutService::class)->placeOrder(User::factory()->create(), [['offer_id' => $offer->id, 'qty' => 1]], $offer->price_cents);
        };

        $this->pay($order)->assertConflict()->assertJsonPath('code', 'reservation_lost');

        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
        $this->assertCount(1, $this->gateway->refunds);
        // The other buyer keeps the key.
        $this->assertSame(['reserved' => 1], $this->keyStatuses($offer));
        $this->assertCount(0, $this->reservedKeys($order));
    }

    public function test_an_order_failed_by_lazy_expiry_during_payment_is_refunded(): void
    {
        $offer = $this->offer(keys: 1);
        $order = $this->placed($offer);
        $this->gateway->duringCharge = function () use ($order) {
            Carbon::setTestNow(now()->addMinutes(20));
            $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.status', 'failed');
        };

        $this->pay($order)->assertConflict()->assertJsonPath('code', 'reservation_expired');

        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
        $this->assertCount(1, $this->gateway->refunds);
        $this->assertSame(['available' => 1], $this->keyStatuses($offer));
    }

    public function test_missing_keys_are_detected_before_charging(): void
    {
        // Cannot happen through the API (a pending order's keys cannot be taken
        // before expires_at); checked anyway before any money moves.
        $offer = $this->offer(keys: 2);
        $order = $this->placed($offer, 2);
        $this->reservedKeys($order)->first()->update(['status' => 'available', 'order_item_id' => null, 'reserved_until' => null]);

        $this->pay($order)->assertConflict()->assertJsonPath('code', 'reservation_lost');

        $this->assertSame([], $this->gateway->charges);
        $this->assertSame(OrderStatus::Failed, $order->fresh()->status);
        $this->assertSame(['available' => 2], $this->keyStatuses($offer));
    }

    // ---------------------------------------------------------------- orders that cannot be paid

    #[DataProvider('finishedStatuses')]
    public function test_finished_orders_cannot_be_paid(OrderStatus $status): void
    {
        $order = $this->placed($this->offer());
        $order->update(['status' => $status]);

        $this->pay($order)->assertConflict()
            ->assertJsonPath('code', 'order_not_payable')
            ->assertJsonPath('order.status', $status->value);
        $this->assertSame([], $this->gateway->charges);
    }

    public static function finishedStatuses(): array
    {
        return [
            'failed' => [OrderStatus::Failed],
            'expired' => [OrderStatus::Expired],
            'cancelled' => [OrderStatus::Cancelled],
        ];
    }

    public function test_a_replaced_order_cannot_be_paid(): void
    {
        $offer = $this->offer(keys: 2);
        $old = $this->placed($offer);
        $this->placed($offer);

        $this->pay($old)->assertConflict()->assertJsonPath('code', 'order_not_payable');
    }

    public function test_someone_elses_order_is_not_found(): void
    {
        $order = $this->placed($this->offer());
        $this->actingAsSomeoneElse();

        $this->pay($order)->assertNotFound();
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame([], $this->gateway->charges);
    }

    public function test_unknown_order(): void
    {
        $this->pay(999)->assertNotFound();
    }

    // ---------------------------------------------------------------- card validation

    #[DataProvider('invalidCards')]
    public function test_card_validation(string $number, string $expiry, string $cvc, string $field): void
    {
        $order = $this->placed($this->offer());

        $this->pay($order, $number, $expiry, $cvc)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame([], $this->gateway->charges);
    }

    public static function invalidCards(): array
    {
        return [
            'a real-looking card' => ['5555 5555 5555 4444', '12/30', '123', 'card_number'],
            'letters' => ['4242 4242 4242 424x', '12/30', '123', 'card_number'],
            'test card with a digit missing' => ['4242 4242 4242 424', '12/30', '123', 'card_number'],
            'empty number' => ['', '12/30', '123', 'card_number'],
            'month 13' => [self::SUCCESS, '13/30', '123', 'card_expiry'],
            'no slash' => [self::SUCCESS, '1230', '123', 'card_expiry'],
            'last month' => [self::SUCCESS, '10/26', '123', 'card_expiry'],
            'cvc too short' => [self::SUCCESS, '12/30', '12', 'card_cvc'],
            'cvc letters' => [self::SUCCESS, '12/30', 'abc', 'card_cvc'],
        ];
    }

    public function test_a_card_expiring_this_month_is_accepted(): void
    {
        $this->pay($this->placed($this->offer()), expiry: '11/26')->assertOk();
    }

    public function test_the_card_number_is_not_echoed_back(): void
    {
        $order = $this->placed($this->offer());

        $body = $this->pay($order, '5555 5555 5555 4444')->assertUnprocessable()->getContent();

        $this->assertStringNotContainsString('5555', $body);
    }

    public function test_spaces_in_the_card_number_are_ignored(): void
    {
        $this->pay($this->placed($this->offer()), '4242424242424242')->assertOk();
    }

    // ---------------------------------------------------------------- the fake gateway itself

    public function test_fake_gateway_is_idempotent_per_key(): void
    {
        $fake = new FakePaymentGateway;
        $card = new Card(FakePaymentGateway::SUCCESS_CARD, 12, 2030, '123');

        $a = $fake->charge('order-1', 1000, 'EUR', $card);
        $b = $fake->charge('order-1', 1000, 'EUR', $card);
        $c = $fake->charge('order-2', 1000, 'EUR', $card);

        $this->assertTrue($a->succeeded);
        $this->assertSame($a->reference, $b->reference);
        $this->assertNotSame($a->reference, $c->reference);
        $this->assertFalse($fake->charge('order-3', 1000, 'EUR', new Card(FakePaymentGateway::DECLINED_CARD, 12, 2030, '123'))->succeeded);
    }

    public function test_card_is_masked_in_debug_output(): void
    {
        $dump = print_r(new Card('4242424242424242', 12, 2030, '123'), true);

        $this->assertStringNotContainsString('4242424242424242', $dump);
        $this->assertStringNotContainsString('123', $dump);
    }

    public function test_the_card_number_never_reaches_the_log_or_the_database(): void
    {
        $log = storage_path('logs/laravel.log');
        $before = is_file($log) ? filesize($log) : 0;

        $this->pay($this->placed($this->offer()))->assertOk();

        $written = is_file($log) ? (string) file_get_contents($log, offset: $before) : '';
        $this->assertStringContainsString('fake_payment.charged', $written);
        $this->assertStringNotContainsString('4242424242424242', $written);
        $this->assertStringNotContainsString('4242 4242 4242 4242', $written);
        $this->assertSame(0, DB::table('orders')->where('payment_reference', 'like', '%4242424242%')->count());
    }
}
