<?php

namespace Tests\Feature\Checkout;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\ProductKey;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /api/orders: price the cart, create a pending order, reserve its keys
 * (PLAN.md step 1, D9, D31, D34).
 */
class PlaceOrderTest extends CheckoutTestCase
{
    // ---------------------------------------------------------------- success

    public function test_creates_a_pending_order_and_reserves_the_keys(): void
    {
        $offer = $this->offer(priceCents: 1999, keys: 5);
        $keyIds = ProductKey::where('offer_id', $offer->id)->orderBy('id')->pluck('id');

        $response = $this->place([[$offer, 2]])->assertCreated();

        $order = Order::sole();
        $response->assertExactJson(['data' => [
            'id' => $order->id,
            'status' => 'pending',
            'total_cents' => 3998,
            'currency' => 'EUR',
            'created_at' => '2026-11-13T12:00:00+00:00',
            'expires_at' => '2026-11-13T12:15:00+00:00',
            'seconds_left' => 900,
            'paid_at' => null,
            'items' => [[
                'offer_id' => $offer->id,
                'product_name' => 'Elden Ring',
                'seller_name' => 'KeyHub',
                'product_slug' => $offer->product->slug,
                'platform' => $offer->product->platform->name,
                'region' => $offer->product->region->name,
                'quantity' => 2,
                'unit_price_cents' => 1999,
                'line_total_cents' => 3998,
                'keys' => null,
            ]],
        ]]);

        $this->assertSame($this->buyer->id, $order->user_id);
        // The lowest ids, reserved until the order's deadline, linked to its item.
        $reserved = $this->reservedKeys($order);
        $this->assertSame($keyIds->take(2)->all(), $reserved->pluck('id')->all());
        foreach ($reserved as $key) {
            $this->assertSame('2026-11-13 12:15:00', $key->reserved_until->toDateTimeString());
            $this->assertSame(OrderItem::sole()->id, $key->order_item_id);
        }
        $this->assertSame(['available' => 3, 'reserved' => 2], $this->keyStatuses($offer));
    }

    public function test_several_lines_one_order(): void
    {
        $a = $this->offer(1000, 2, 'Alpha');
        $b = $this->offer(250, 4, 'Beta');

        $this->place([[$a, 1], [$b, 3]])->assertCreated()->assertJsonPath('data.total_cents', 1750);

        $order = Order::sole();
        $this->assertSame([[$a->id, 1, 1000], [$b->id, 3, 250]], $order->items()->orderBy('id')->get()
            ->map(fn ($i) => [$i->offer_id, $i->quantity, $i->unit_price_cents])->all());
        $this->assertCount(4, $this->reservedKeys($order));
    }

    public function test_names_and_price_are_copied_at_purchase_time(): void
    {
        $offer = $this->offer(1000);
        $order = $this->placed($offer);

        $offer->update(['price_cents' => 5000]);
        $offer->product->update(['name' => 'Renamed']);
        $offer->seller->update(['name' => 'Other seller']);

        $this->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('data.items.0.unit_price_cents', 1000)
            ->assertJsonPath('data.items.0.product_name', 'Elden Ring')
            ->assertJsonPath('data.items.0.seller_name', 'KeyHub')
            ->assertJsonPath('data.total_cents', 1000);
    }

    public function test_prices_sent_by_the_client_are_ignored(): void
    {
        $offer = $this->offer(1999);

        $this->postJson('/api/orders', [
            'lines' => [['offer_id' => $offer->id, 'qty' => 1, 'unit_price_cents' => 1]],
            'expected_total_cents' => 1999,
            'total_cents' => 1,
        ])->assertCreated()->assertJsonPath('data.items.0.unit_price_cents', 1999);
    }

    public function test_a_key_whose_reservation_expired_is_taken_over(): void
    {
        $offer = $this->offer(keys: 0);
        $stale = ProductKey::factory()->for($offer)->reserved(now()->subSecond())->create();
        $oldItem = $stale->fresh()->order_item_id;

        $order = $this->placed($offer);

        $stale->refresh();
        $this->assertSame('reserved', $stale->status);
        $this->assertNotSame($oldItem, $stale->order_item_id);
        $this->assertSame($order->items()->value('id'), $stale->order_item_id);
    }

    // ---------------------------------------------------------------- 409: nothing is created

    public function test_price_changed(): void
    {
        $offer = $this->offer(1999);

        $this->place([[$offer, 2]], expectedTotal: 3000)
            ->assertConflict()
            ->assertJsonPath('code', 'price_changed')
            ->assertJsonPath('quote.total_cents', 3998)
            ->assertJsonPath('quote.can_checkout', true)
            ->assertJsonMissingPath('order');

        $this->assertNothingCreated();
    }

    public function test_price_dropped_is_also_a_conflict(): void
    {
        // The buyer must see the amount they pay, even when it is lower.
        $offer = $this->offer(1000);

        $this->place([[$offer, 1]], expectedTotal: 1200)->assertConflict()->assertJsonPath('code', 'price_changed');
        $this->assertNothingCreated();
    }

    public function test_not_enough_stock(): void
    {
        $offer = $this->offer(keys: 2);

        $this->place([[$offer, 3]])
            ->assertConflict()
            ->assertJsonPath('code', 'out_of_stock')
            ->assertJsonPath('quote.lines.0.status', 'insufficient_stock')
            ->assertJsonPath('quote.lines.0.stock', 2);

        $this->assertNothingCreated();
    }

    public function test_one_bad_line_blocks_the_whole_order(): void
    {
        $ok = $this->offer(keys: 3, name: 'Alpha');
        $soldOut = $this->offer(keys: 0, name: 'Beta');

        $this->place([[$ok, 1], [$soldOut, 1]])
            ->assertConflict()
            ->assertJsonPath('code', 'out_of_stock')
            ->assertJsonPath('quote.lines.1.status', 'sold_out');

        $this->assertNothingCreated();
    }

    public function test_withdrawn_offer(): void
    {
        $offer = $this->offer();
        $offer->update(['is_active' => false]);

        $this->place([[$offer, 1]])->assertConflict()
            ->assertJsonPath('code', 'out_of_stock')
            ->assertJsonPath('quote.lines.0.status', 'unavailable');
    }

    public function test_keys_reserved_by_another_buyer_are_not_taken(): void
    {
        $offer = $this->offer(keys: 0);
        ProductKey::factory()->for($offer)->reserved(now()->addMinute())->create();
        ProductKey::factory()->for($offer)->sold()->create();

        $this->place([[$offer, 1]])->assertConflict()->assertJsonPath('code', 'out_of_stock');
    }

    public function test_out_of_stock_is_checked_before_price(): void
    {
        // Both wrong: the stock problem is the one the buyer must act on.
        $offer = $this->offer(keys: 1);

        $this->place([[$offer, 2]], expectedTotal: 1)->assertConflict()->assertJsonPath('code', 'out_of_stock');
    }

    // ---------------------------------------------------------------- one pending order per user

    public function test_a_new_order_cancels_the_previous_pending_one(): void
    {
        $offer = $this->offer(keys: 3);
        $first = $this->placed($offer, 2);

        $second = $this->placed($offer, 2);

        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $second->status);
        $this->assertCount(0, $this->reservedKeys($first));
        $this->assertCount(2, $this->reservedKeys($second));
        $this->assertSame(['available' => 1, 'reserved' => 2], $this->keyStatuses($offer));
    }

    public function test_checking_out_again_can_take_back_your_own_last_key(): void
    {
        // Without the rule, the buyer's own 15-minute reservation would make
        // the last key "out of stock" for them.
        $offer = $this->offer(keys: 1);
        $first = $this->placed($offer);

        $second = $this->placed($offer);

        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);
        $this->assertCount(1, $this->reservedKeys($second));
    }

    public function test_a_conflict_does_not_cancel_the_previous_order(): void
    {
        $offer = $this->offer(1000, keys: 1);
        $first = $this->placed($offer);

        $this->place([[$offer, 1]], expectedTotal: 1)->assertConflict();

        $this->assertSame(OrderStatus::Pending, $first->fresh()->status);
        $this->assertCount(1, $this->reservedKeys($first));
    }

    public function test_an_order_being_paid_is_not_cancelled(): void
    {
        $offer = $this->offer(keys: 2);
        $paying = $this->placed($offer);
        $paying->update(['status' => OrderStatus::Paying]);

        $this->placed($offer);

        $this->assertSame(OrderStatus::Paying, $paying->fresh()->status);
        $this->assertCount(1, $this->reservedKeys($paying));
    }

    public function test_other_users_orders_are_not_touched(): void
    {
        $offer = $this->offer(keys: 2);
        $theirs = $this->placed($offer);

        $this->actingAsSomeoneElse();
        $this->placed($offer);

        $this->assertSame(OrderStatus::Pending, $theirs->fresh()->status);
        $this->assertCount(1, $this->reservedKeys($theirs));
    }

    // ---------------------------------------------------------------- access and validation

    public function test_requires_sign_in(): void
    {
        $offer = $this->offer();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', '')->place([[$offer, 1]])->assertUnauthorized();
        $this->assertNothingCreated();
    }

    #[DataProvider('invalidBodies')]
    public function test_validation(array $body, string $field): void
    {
        $this->offer();

        $this->postJson('/api/orders', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidBodies(): array
    {
        $line = [['offer_id' => 1, 'qty' => 1]];

        return [
            'expected total missing' => [['lines' => $line], 'expected_total_cents'],
            'expected total negative' => [['lines' => $line, 'expected_total_cents' => -1], 'expected_total_cents'],
            'expected total text' => [['lines' => $line, 'expected_total_cents' => 'ten'], 'expected_total_cents'],
            'expected total too large' => [['lines' => $line, 'expected_total_cents' => 2147483648], 'expected_total_cents'],
            'lines missing' => [['expected_total_cents' => 0], 'lines'],
            'qty too high (cart rules)' => [['lines' => [['offer_id' => 1, 'qty' => 11]], 'expected_total_cents' => 0], 'lines.0.qty'],
            'duplicate offer (cart rules)' => [['lines' => [['offer_id' => 1, 'qty' => 1], ['offer_id' => 1, 'qty' => 1]], 'expected_total_cents' => 0], 'lines.0.offer_id'],
        ];
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, DB::table('product_keys')->where('status', 'reserved')->count());
    }
}
