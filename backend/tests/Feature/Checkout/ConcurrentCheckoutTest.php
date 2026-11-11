<?php

namespace Tests\Feature\Checkout;

use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductKey;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Two buyers at the same time, for real (D34).
 *
 * The other checkout runs on a SECOND database connection, i.e. a second
 * PostgreSQL session with its own transaction and its own row locks, exactly
 * like a second PHP process serving another buyer. Its transaction is left
 * open at the critical moment, so the interleaving is deterministic instead
 * of depending on timing.
 *
 * That needs committed data: the usual RefreshDatabase wraps each test in a
 * transaction the second session could not see. DatabaseTruncation commits
 * normally and empties the tables before each test instead.
 *
 * Tables are emptied after each test too (see tearDown).
 *
 * lock_timeout turns "would wait for the other buyer" into an error instead
 * of a hang, so a version that waits fails the test.
 *
 * The truly parallel version (30 buyers, real HTTP server, many processes):
 * benchmarks/checkout/race.php.
 */
class ConcurrentCheckoutTest extends TestCase
{
    use DatabaseTruncation;

    private Connection $rival;

    private RecordingGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.rival' => config('database.connections.'.config('database.default'))]);
        $this->rival = DB::connection('rival');
        DB::statement("SET lock_timeout = '2s'");
        $this->gateway = new RecordingGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        if ($this->rival->transactionLevel() > 0) {
            $this->rival->rollBack();
        }
        DB::purge('rival');
        // DatabaseTruncation only empties tables BEFORE each test. Committed
        // rows left behind would make the next RefreshDatabase class start
        // from a non-empty database (DemoSeedTest's seeder would then skip,
        // like a second container start, D20), so clean up after too.
        DB::statement('SET lock_timeout = 0');
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_the_last_key_locked_by_another_checkout_is_out_of_stock_at_once(): void
    {
        $offer = Offer::factory()->price(1000)->withKeys(1)->create();

        // Buyer A is in the middle of reserving the last key: its SELECT ...
        // FOR UPDATE has locked the row and its transaction is still open.
        $this->rival->beginTransaction();
        $this->rival->select('SELECT id FROM product_keys WHERE offer_id = ? FOR UPDATE', [$offer->id]);

        // Buyer B: no waiting (SKIP LOCKED), a clean 409, nothing created.
        $started = microtime(true);
        $this->placeAs(User::factory()->create(), $offer)
            ->assertConflict()
            ->assertJsonPath('code', 'out_of_stock');
        $this->assertLessThan(1.0, microtime(true) - $started, 'buyer B waited for buyer A');
        $this->assertSame(0, Order::count());

        // A gives up (rollback): the key is free again for the next buyer.
        $this->rival->rollBack();
        $this->placeAs(User::factory()->create(), $offer)->assertCreated();
    }

    public function test_a_locked_key_is_skipped_and_the_next_one_is_taken(): void
    {
        $offer = Offer::factory()->price(1000)->withKeys(2)->create();
        [$first, $second] = ProductKey::where('offer_id', $offer->id)->orderBy('id')->pluck('id')->all();

        $this->rival->beginTransaction();
        $this->rival->select('SELECT id FROM product_keys WHERE id = ? FOR UPDATE', [$first]);

        $order = Order::findOrFail($this->placeAs(User::factory()->create(), $offer)->assertCreated()->json('data.id'));

        $this->assertSame([$second], ProductKey::whereIn('order_item_id', $order->items()->select('id'))->pluck('id')->all());
    }

    public function test_a_reservation_committed_by_another_buyer_is_respected(): void
    {
        $offer = Offer::factory()->price(1000)->withKeys(1)->create();
        $this->placeAs(User::factory()->create(), $offer)->assertCreated();

        $this->placeAs(User::factory()->create(), $offer)->assertConflict()->assertJsonPath('code', 'out_of_stock');

        $this->assertSame(1, ProductKey::where('status', 'reserved')->count());
    }

    public function test_two_place_order_requests_of_one_buyer_run_one_after_the_other(): void
    {
        // Request 1 (first click) is inside its transaction and holds the
        // buyer's users row. Request 2 (second click) must wait for it, so it
        // sees request 1's order and replaces it, instead of both creating a
        // pending order and holding twice the keys.
        $buyer = User::factory()->create();
        $offer = Offer::factory()->price(1000)->withKeys(2)->create();
        $first = $this->placeAs($buyer, $offer)->assertCreated()->json('data.id');

        // The rival takes FOR NO KEY UPDATE, not FOR UPDATE: inserting an order
        // checks its foreign key to users with a FOR KEY SHARE lock, which
        // FOR UPDATE would block too. Without the explicit user lock, request 2
        // would then still wait, but only at the INSERT, after it had already
        // looked for pending orders to cancel: too late. NO KEY UPDATE does not
        // block the foreign key check, so only checkout's own lock makes it wait.
        $this->rival->beginTransaction();
        $this->rival->select('SELECT id FROM users WHERE id = ? FOR NO KEY UPDATE', [$buyer->id]);

        DB::statement("SET lock_timeout = '300ms'");
        $started = microtime(true);
        $this->placeAs($buyer, $offer)->assertServerError();
        $this->assertGreaterThan(0.25, microtime(true) - $started, 'request 2 did not wait for request 1');

        $this->rival->commit();
        $second = $this->placeAs($buyer, $offer)->assertCreated()->json('data.id');
        $this->assertSame(OrderStatus::Cancelled, Order::find($first)->status);
        $this->assertSame(1, Order::where('user_id', $buyer->id)->where('status', 'pending')->count());
        $this->assertNotSame($first, $second);
    }

    public function test_a_second_pay_waits_for_the_first_and_then_does_not_charge(): void
    {
        // Pay request 1 holds the order's row lock (transaction A, about to
        // mark it `paying`). Pay request 2 must WAIT for that lock (not skip
        // it, not read the old status), then see `paying` and stop.
        $buyer = User::factory()->create();
        $offer = Offer::factory()->price(1000)->withKeys(1)->create();
        $orderId = $this->placeAs($buyer, $offer)->assertCreated()->json('data.id');

        $this->rival->beginTransaction();
        $this->rival->select('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        $this->rival->update("UPDATE orders SET status = 'paying' WHERE id = ?", [$orderId]);

        // While request 1 holds the lock, request 2 waits (here: until lock_timeout).
        DB::statement("SET lock_timeout = '300ms'");
        $started = microtime(true);
        $this->payAs($buyer, $orderId)->assertServerError();
        $this->assertGreaterThan(0.25, microtime(true) - $started, 'request 2 did not wait for the lock');

        // Request 1 commits `paying`; request 2 now sees it and charges nothing.
        $this->rival->commit();
        $this->payAs($buyer, $orderId)->assertConflict()->assertJsonPath('code', 'payment_in_progress');
        $this->assertSame([], $this->gateway->charges);
        $this->assertSame(OrderStatus::Paying, Order::find($orderId)->status);
    }

    private function placeAs(User $user, Offer $offer): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/orders', [
            'lines' => [['offer_id' => $offer->id, 'qty' => 1]],
            'expected_total_cents' => $offer->price_cents,
        ]);
    }

    private function payAs(User $user, int $orderId): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson("/api/orders/{$orderId}/pay", [
            'card_number' => '4242 4242 4242 4242', 'card_expiry' => '12/30', 'card_cvc' => '123',
        ]);
    }
}
