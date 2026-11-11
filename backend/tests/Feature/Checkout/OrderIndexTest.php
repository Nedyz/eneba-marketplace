<?php

namespace Tests\Feature\Checkout;

use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * GET /api/orders: the order history (D35).
 */
class OrderIndexTest extends CheckoutTestCase
{
    /** Places and pays an order for the current user. */
    private function paid(Offer $offer, int $qty = 1): Order
    {
        $order = $this->placed($offer, $qty);
        $this->pay($order)->assertOk();

        return $order;
    }

    /** @return list<int> the order ids on the page, in order */
    private function listedIds(string $query = ''): array
    {
        return $this->getJson("/api/orders{$query}")->assertOk()->json('data.*.id');
    }

    public function test_lists_own_orders_newest_first(): void
    {
        $offer = $this->offer(keys: 10);
        $first = $this->paid($offer);
        Carbon::setTestNow(now()->addMinute());
        $second = $this->paid($offer);
        Carbon::setTestNow(now()->addMinute());
        $pending = $this->placed($offer);

        $buyer = $this->buyer;
        $this->actingAsSomeoneElse();
        $this->paid($offer);
        Sanctum::actingAs($buyer);

        $this->assertSame([$pending->id, $second->id, $first->id], $this->listedIds());
    }

    public function test_an_order_summary_has_status_date_items_and_total_but_no_keys(): void
    {
        $offer = $this->offer(priceCents: 1250, keys: 3, name: 'Hades II');
        $order = $this->paid($offer, 2);

        $response = $this->getJson('/api/orders')->assertOk();

        $response->assertExactJson([
            'data' => [[
                'id' => $order->id,
                'status' => 'paid',
                'total_cents' => 2500,
                'currency' => 'EUR',
                'created_at' => '2026-11-13T12:00:00+00:00',
                'paid_at' => '2026-11-13T12:00:00+00:00',
                'items' => [[
                    'offer_id' => $offer->id,
                    'product_name' => 'Hades II',
                    'product_slug' => $offer->product->slug,
                    'platform' => $offer->product->platform->name,
                    'region' => $offer->product->region->name,
                    'quantity' => 2,
                    'unit_price_cents' => 1250,
                    'line_total_cents' => 2500,
                ]],
            ]],
            'links' => $response->json('links'),
            'meta' => $response->json('meta'),
        ]);
        // The keys stay on the order's own page.
        $this->assertStringNotContainsString('keys', $response->getContent());
    }

    public function test_items_are_listed_in_the_order_they_were_added(): void
    {
        $a = $this->offer(name: 'Alpha');
        $b = $this->offer(name: 'Beta');
        $this->place([[$b, 1], [$a, 2]])->assertCreated();

        $this->getJson('/api/orders')->assertJsonPath('data.0.items.*.product_name', ['Beta', 'Alpha']);
    }

    // ---------------------------------------------------------------- cancelled orders

    public function test_cancelled_orders_are_not_listed(): void
    {
        $offer = $this->offer();
        $replaced = $this->placed($offer);
        $current = $this->placed($offer); // checking out again cancels the first (D34)
        $this->assertSame(OrderStatus::Cancelled, $replaced->fresh()->status);

        $this->getJson('/api/orders')
            ->assertJsonPath('data.*.id', [$current->id])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_cancelled_orders_are_filtered_in_the_query_so_pages_stay_full(): void
    {
        // 11 paid orders, each preceded by a checkout that was replaced:
        // 22 orders in the table, 11 to show.
        $offer = $this->offer(keys: 30);
        for ($i = 0; $i < 11; $i++) {
            $this->placed($offer);
            $this->paid($offer);
            Carbon::setTestNow(now()->addMinute());
        }
        $this->assertSame(22, Order::count());

        $this->getJson('/api/orders')
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 11)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 10);
        $this->getJson('/api/orders?page=2')->assertJsonCount(1, 'data');
    }

    // ---------------------------------------------------------------- pagination

    public function test_orders_of_the_same_second_are_paged_by_id_without_gaps_or_repeats(): void
    {
        $offer = $this->offer(keys: 12);
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->paid($offer)->id; // the clock is frozen: all in one second
        }
        // Without a tiebreak, PostgreSQL returns equal created_at values in the
        // order of their entries in the (user_id, created_at) index, which is
        // the rows' physical order: insertion order, so descending ids came
        // back by luck and the test passed with the tiebreak removed.
        // Rewrite the rows in a scrambled order to break that. The value must
        // change and come back: an UPDATE that leaves indexed columns equal is
        // a HOT update, which keeps the old index entry and changes nothing.
        foreach ([5, 0, 11, 3, 8, 1, 10, 6, 2, 9, 4, 7] as $index) {
            Order::whereKey($ids[$index])->update(['created_at' => now()->subDay()]);
            Order::whereKey($ids[$index])->update(['created_at' => now()]);
        }

        $listed = [...$this->listedIds(), ...$this->listedIds('?page=2')];

        $this->assertSame(array_reverse($ids), $listed);
    }

    public function test_a_page_past_the_end_is_empty(): void
    {
        $this->paid($this->offer());

        $this->getJson('/api/orders?page=3')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_no_orders(): void
    {
        $this->getJson('/api/orders')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_invalid_pages_are_rejected(): void
    {
        foreach (['0', '-1', 'abc', '1.5', '10001', '9223372036854775807'] as $page) {
            $this->getJson("/api/orders?page={$page}")->assertUnprocessable()->assertJsonValidationErrors('page');
        }
        $this->getJson('/api/orders?page=10000')->assertOk();
    }

    public function test_a_page_costs_the_same_number_of_queries_whatever_its_size(): void
    {
        $offers = [$this->offer(keys: 20), $this->offer(keys: 20), $this->offer(keys: 20)];
        $first = Order::findOrFail($this->place([[$offers[0], 1], [$offers[1], 1]])->assertCreated()->json('data.id'));
        $this->pay($first)->assertOk();
        $one = $this->countQueries();

        foreach (range(1, 9) as $i) {
            $order = Order::findOrFail($this->place([[$offers[0], 1], [$offers[1], 1], [$offers[2], 1]])->assertCreated()->json('data.id'));
            $this->pay($order)->assertOk();
        }
        $this->assertSame(10, count($this->listedIds()));

        $this->assertSame($one, $this->countQueries());
    }

    private function countQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/orders')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    // ---------------------------------------------------------------- lazy expiry

    public function test_a_due_pending_order_is_expired_before_listing(): void
    {
        $offer = $this->offer(keys: 1);
        $order = $this->placed($offer);
        Carbon::setTestNow(now()->addMinutes(15)->addSecond());

        $this->getJson('/api/orders')->assertJsonPath('data.0.status', 'expired');

        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);
        $this->assertSame(['available' => 1], $this->keyStatuses($offer));
    }

    public function test_a_pending_order_in_time_stays_pending(): void
    {
        $offer = $this->offer(keys: 1);
        $this->placed($offer);
        Carbon::setTestNow(now()->addMinutes(15));

        $this->getJson('/api/orders')->assertJsonPath('data.0.status', 'pending');
        $this->assertSame(['reserved' => 1], $this->keyStatuses($offer));
    }

    public function test_an_interrupted_payment_is_listed_as_failed_once_its_hold_is_over(): void
    {
        $offer = $this->offer(keys: 1);
        $order = $this->placed($offer);
        $order->update(['status' => OrderStatus::Paying]);
        Carbon::setTestNow(now()->addMinutes(15)->addSecond());

        $this->getJson('/api/orders')->assertJsonPath('data.0.status', 'failed');
        $this->assertSame(['available' => 1], $this->keyStatuses($offer));
    }

    public function test_only_the_readers_own_orders_are_expired(): void
    {
        $offer = $this->offer(keys: 2);
        $theirs = $this->placed($offer);
        $this->actingAsSomeoneElse();
        Carbon::setTestNow(now()->addMinutes(16));

        $this->getJson('/api/orders')->assertOk();

        $this->assertSame(OrderStatus::Pending, $theirs->fresh()->status);
    }

    // ---------------------------------------------------------------- access

    public function test_requires_sign_in(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/orders')->assertUnauthorized();
    }

    public function test_responses_are_never_cached(): void
    {
        $cacheControl = $this->getJson('/api/orders')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }
}
