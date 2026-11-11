<?php

namespace Tests\Feature\Checkout;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Checkout\OrderExpirer;
use Illuminate\Support\Carbon;

/**
 * GET /api/orders/{id} and lazy expiry (D10, D34).
 */
class OrderShowTest extends CheckoutTestCase
{
    public function test_pending_order_has_a_timer_and_no_keys(): void
    {
        $order = $this->placed($this->offer());
        Carbon::setTestNow(now()->addMinutes(5)->addSeconds(30));

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.seconds_left', 570)
            ->assertJsonPath('data.items.0.keys', null);
    }

    public function test_paid_order_shows_its_keys(): void
    {
        $offer = $this->offer(keys: 3);
        $order = $this->placed($offer, 2);
        $codes = $this->reservedKeys($order)->pluck('code')->all();
        $this->pay($order)->assertOk();

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.items.0.keys', $codes);
    }

    public function test_responses_are_never_cached(): void
    {
        $order = $this->placed($this->offer());

        $cacheControl = $this->getJson("/api/orders/{$order->id}")->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_someone_elses_order_is_not_found(): void
    {
        $order = $this->placed($this->offer());
        $this->actingAsSomeoneElse();

        $this->getJson("/api/orders/{$order->id}")->assertNotFound();
    }

    public function test_unknown_and_malformed_ids(): void
    {
        $this->getJson('/api/orders/999')->assertNotFound();
        $this->getJson('/api/orders/abc')->assertNotFound();
    }

    public function test_requires_sign_in(): void
    {
        $order = $this->placed($this->offer());
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/orders/{$order->id}")->assertUnauthorized();
    }

    // ---------------------------------------------------------------- lazy expiry on read

    public function test_reading_an_expired_pending_order_expires_it(): void
    {
        $offer = $this->offer(keys: 1);
        $order = $this->placed($offer);
        Carbon::setTestNow(now()->addMinutes(15)->addSecond());

        $this->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.seconds_left', null)
            ->assertJsonPath('data.expires_at', null);

        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);
        $this->assertSame(['available' => 1], $this->keyStatuses($offer));
    }

    public function test_reading_in_time_changes_nothing(): void
    {
        $order = $this->placed($this->offer());
        Carbon::setTestNow(now()->addMinutes(15));

        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.status', 'pending')->assertJsonPath('data.seconds_left', 0);
        $this->assertCount(1, $this->reservedKeys($order));
    }

    public function test_an_interrupted_payment_fails_once_the_hold_is_over(): void
    {
        // The process died between charging and recording the result.
        $offer = $this->offer(keys: 1);
        $order = $this->placed($offer);
        $order->update(['status' => OrderStatus::Paying]);
        Carbon::setTestNow(now()->addMinutes(15)->addSecond());

        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('data.status', 'failed');
        $this->assertSame(['available' => 1], $this->keyStatuses($offer));
    }

    public function test_expiry_releases_only_keys_still_linked_to_the_order(): void
    {
        // The key was taken over by another buyer after the reservation ran
        // out; expiring the old order must not free the new buyer's key.
        $offer = $this->offer(keys: 1);
        $order = $this->placed($offer);
        Carbon::setTestNow(now()->addMinutes(16));
        $this->actingAsSomeoneElse();
        $theirs = $this->placed($offer);

        $order->refresh();
        app(OrderExpirer::class)->expireIfDue($order);

        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);
        $this->assertCount(1, $this->reservedKeys($theirs));
    }

    // ---------------------------------------------------------------- orders:expire (scheduler)

    public function test_expire_command(): void
    {
        $offer = $this->offer(keys: 4);
        $due = $this->placed($offer);
        $this->actingAsSomeoneElse();
        $paying = $this->placed($offer);
        $paying->update(['status' => OrderStatus::Paying]);
        Carbon::setTestNow(now()->addMinutes(10));
        $this->actingAsSomeoneElse();
        $fresh = $this->placed($offer);
        $this->actingAsSomeoneElse();
        $paid = $this->placed($offer);
        $this->pay($paid)->assertOk();
        Carbon::setTestNow(now()->addMinutes(6)); // the first two are past 15 minutes

        $this->artisan('orders:expire')->expectsOutput('Expired 2 order(s).')->assertSuccessful();

        $this->assertSame(
            [OrderStatus::Expired, OrderStatus::Failed, OrderStatus::Pending, OrderStatus::Paid],
            [$due->fresh()->status, $paying->fresh()->status, $fresh->fresh()->status, $paid->fresh()->status],
        );
        $this->assertSame(['available' => 2, 'reserved' => 1, 'sold' => 1], $this->keyStatuses($offer));
        $this->assertSame(4, Order::count());
    }
}
