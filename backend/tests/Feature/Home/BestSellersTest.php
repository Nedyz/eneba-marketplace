<?php

namespace Tests\Feature\Home;

use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/home/best-sellers (D32): units sold in the last 30 days, remaining
 * places filled by all-time units, sold-out products left out, 12 at most.
 *
 * Products are created so that id order (the last tie-break) disagrees with the
 * expected order, so removing the rule under test fails the test.
 */
class BestSellersTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-20 12:00:00');
        $this->buyer = User::factory()->create();
    }

    /** A product with one in-stock offer. */
    private function product(string $slug): Product
    {
        $product = Product::factory()->create(['slug' => $slug]);
        Offer::factory()->for($product)->price(1999)->withKeys(3)->create();

        return $product;
    }

    /**
     * Records a sale of $qty units of the product's first offer (or $offer).
     * paid_at is set for every status, so a test about status only checks the
     * status rule, not "paid_at is null".
     */
    private function sell(Product $product, int $qty, CarbonInterface $paidAt, string $status = 'paid', ?Offer $offer = null): void
    {
        $offer ??= $product->offers()->orderBy('id')->firstOrFail();
        $order = Order::create([
            'user_id' => $this->buyer->id, 'status' => $status, 'total_cents' => $offer->price_cents * $qty,
            'expires_at' => $paidAt, 'paid_at' => $paidAt,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'offer_id' => $offer->id, 'quantity' => $qty,
            'unit_price_cents' => $offer->price_cents, 'product_name' => $product->name, 'seller_name' => 'Seller',
        ]);
    }

    /** @return list<string> */
    private function slugs(): array
    {
        return $this->getJson('/api/home/best-sellers')->assertOk()->json('data.*.slug');
    }

    public function test_returns_store_cards(): void
    {
        $product = $this->product('elden-ring');
        $this->sell($product, 1, now()->subDay());

        $this->getJson('/api/home/best-sellers')
            ->assertJsonPath('data.0.slug', 'elden-ring')
            ->assertJsonPath('data.0.price', ['from_cents' => 1999, 'original_cents' => null, 'discount_percent' => null, 'currency' => 'EUR'])
            ->assertJsonStructure(['data' => [['id', 'slug', 'name', 'image_url', 'release_date', 'platform', 'region', 'price']]]);
    }

    public function test_ranks_by_units_not_by_number_of_orders(): void
    {
        $twoOrders = $this->product('two-orders');
        $oneBigOrder = $this->product('one-big-order');
        $this->sell($twoOrders, 1, now()->subDays(2));
        $this->sell($twoOrders, 1, now()->subDays(3));
        $this->sell($oneBigOrder, 3, now()->subDay());

        $this->assertSame(['one-big-order', 'two-orders'], $this->slugs());
    }

    public function test_units_are_summed_over_all_offers_of_a_product(): void
    {
        $oneOffer = $this->product('one-offer');
        $twoOffers = $this->product('two-offers');
        $second = Offer::factory()->for($twoOffers)->price(2499)->withKeys(3)->create();
        $this->sell($oneOffer, 3, now()->subDay());
        $this->sell($twoOffers, 2, now()->subDay());
        $this->sell($twoOffers, 2, now()->subDay(), offer: $second);

        // Per offer, one-offer (3) would beat each of two-offers' offers (2 + 2).
        $this->assertSame(['two-offers', 'one-offer'], $this->slugs());
    }

    public function test_only_paid_orders_count(): void
    {
        $paid = $this->product('paid');
        $notPaid = $this->product('not-paid');
        $mixed = $this->product('mixed');
        $this->sell($paid, 2, now()->subDay());
        $this->sell($mixed, 1, now()->subDay());
        foreach (['pending', 'failed', 'expired'] as $status) {
            $this->sell($notPaid, 5, now()->subDay(), $status);
            $this->sell($mixed, 5, now()->subDay(), $status);
        }

        $this->assertSame(['paid', 'mixed'], $this->slugs());
    }

    public function test_a_recent_sale_ranks_above_more_older_sales(): void
    {
        $old = $this->product('old-hit');
        $recent = $this->product('recent');
        $this->sell($old, 10, now()->subDays(40));
        $this->sell($recent, 1, now()->subDay());

        $this->assertSame(['recent', 'old-hit'], $this->slugs());
    }

    public function test_window_start_is_exactly_30_days_ago_inclusive(): void
    {
        $justOutside = $this->product('just-outside');
        $onTheEdge = $this->product('on-the-edge');
        $this->sell($justOutside, 5, now()->subDays(30)->subSecond());
        $this->sell($onTheEdge, 1, now()->subDays(30));

        $this->assertSame(['on-the-edge', 'just-outside'], $this->slugs());
    }

    public function test_ties_in_the_window_are_broken_by_all_time_units(): void
    {
        $fewer = $this->product('fewer-all-time');
        $more = $this->product('more-all-time');
        $this->sell($fewer, 1, now()->subDay());
        $this->sell($more, 1, now()->subDay());
        $this->sell($more, 4, now()->subDays(60));

        $this->assertSame(['more-all-time', 'fewer-all-time'], $this->slugs());
    }

    /**
     * The production case (D32): demo orders were seeded once, so weeks later
     * every one of them is older than the window. The block must still be full,
     * ranked by all-time units.
     */
    public function test_with_every_paid_order_older_than_30_days_the_block_shows_all_time_best_sellers(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00'); // "seed time"
        $products = [];
        foreach (range(1, 14) as $i) {
            $products[$i] = $this->product("game-{$i}");
            // game-14 sold the most, game-1 the least: the reverse of id order.
            $this->sell($products[$i], $i, now()->subDays($i));
        }

        Carbon::setTestNow('2027-01-15 12:00:00'); // weeks later: nothing in the window

        $this->assertSame(
            array_map(fn ($i) => "game-{$i}", range(14, 3, -1)),
            $this->slugs(),
        );
    }

    public function test_fill_takes_only_the_places_the_window_leaves_empty(): void
    {
        $oldA = $this->product('old-3-units');
        $oldB = $this->product('old-7-units');
        $recentA = $this->product('recent-1-unit');
        $recentB = $this->product('recent-2-units');
        $this->sell($oldA, 3, now()->subDays(45));
        $this->sell($oldB, 7, now()->subDays(50));
        $this->sell($recentA, 1, now()->subDays(5));
        $this->sell($recentB, 2, now()->subDays(10));

        $this->assertSame(['recent-2-units', 'recent-1-unit', 'old-7-units', 'old-3-units'], $this->slugs());
    }

    public function test_sold_out_products_are_left_out(): void
    {
        $soldOut = Product::factory()->create(['slug' => 'sold-out']);
        $offer = Offer::factory()->for($soldOut)->price(999)->create(); // no keys
        $inStock = $this->product('in-stock');
        $this->sell($soldOut, 10, now()->subDay(), offer: $offer);
        $this->sell($inStock, 1, now()->subDay());

        $this->assertSame(['in-stock'], $this->slugs());
    }

    public function test_products_never_sold_are_not_listed(): void
    {
        $this->product('never-sold');

        $this->assertSame([], $this->slugs());
    }

    public function test_at_most_12_products_and_the_least_sold_drop_out(): void
    {
        foreach (range(1, 13) as $i) {
            $this->sell($this->product("game-{$i}"), $i, now()->subDay());
        }

        $slugs = $this->slugs();
        $this->assertCount(12, $slugs);
        $this->assertNotContains('game-1', $slugs);
    }

    public function test_query_count_does_not_grow_with_the_number_of_products(): void
    {
        foreach (range(1, 6) as $i) {
            $this->sell($this->product("game-{$i}"), 1, now()->subDay());
        }

        DB::enableQueryLog();
        $this->getJson('/api/home/best-sellers')->assertOk();

        // Ranking + eager-loaded platforms + regions.
        $this->assertCount(3, DB::getQueryLog());
    }
}
