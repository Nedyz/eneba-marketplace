<?php

namespace Tests\Feature\Catalog;

use App\Models\Offer;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\Region;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/products/{slug}: product page with offer comparison (D30).
 *
 * Offer order is: in stock first, then price, then offer id. Each ordering
 * test is built so that the rule under test disagrees with the next rule (or
 * with creation order), so removing that rule fails the test.
 */
class ProductShowTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = Product::factory()
            ->for(Platform::factory()->create(['name' => 'Steam', 'slug' => 'steam']))
            ->for(Region::factory()->create(['name' => 'Global', 'slug' => 'global']))
            ->create([
                'slug' => 'elden-ring-steam-global',
                'name' => 'Elden Ring',
                'description' => 'Rise, Tarnished.',
                'release_date' => '2022-02-25',
            ]);
    }

    // ---------------------------------------------------------------- product

    public function test_returns_the_product_details(): void
    {
        $this->getJson('/api/products/elden-ring-steam-global')
            ->assertOk()
            ->assertJsonPath('data.slug', 'elden-ring-steam-global')
            ->assertJsonPath('data.name', 'Elden Ring')
            ->assertJsonPath('data.description', 'Rise, Tarnished.')
            ->assertJsonPath('data.release_date', '2022-02-25')
            ->assertJsonPath('data.platform', ['slug' => 'steam', 'name' => 'Steam'])
            ->assertJsonPath('data.region', ['slug' => 'global', 'name' => 'Global']);
    }

    public function test_offer_shape(): void
    {
        $seller = Seller::factory()->create(['name' => 'KeyHub', 'rating' => 4.85]);
        $offer = Offer::factory()->for($this->product)->for($seller)->price(1999, 2500)->withKeys(3)->create();

        $data = $this->getJson('/api/products/elden-ring-steam-global')->assertOk()->json('data');

        // Exactly these fields: nothing from the database leaks by accident.
        $this->assertEqualsCanonicalizing(
            ['id', 'slug', 'name', 'image_url', 'release_date', 'platform', 'region', 'price', 'description', 'offers'],
            array_keys($data),
        );
        $this->assertSame([[
            'id' => $offer->id,
            'seller' => ['id' => $seller->id, 'name' => 'KeyHub', 'rating' => 4.85],
            'price_cents' => 1999,
            'original_price_cents' => 2500,
            'discount_percent' => 20, // 20.04%, rounded down
            'currency' => 'EUR',
            'stock' => 3,
        ]], $data['offers']);
    }

    public function test_unknown_slug_is_a_json_404(): void
    {
        $this->getJson('/api/products/no-such-game')
            ->assertNotFound()
            ->assertJsonPath('message', 'Product not found.');
    }

    public function test_filters_route_is_not_taken_for_a_slug(): void
    {
        // /products/{slug} is registered after /products/filters; if the order
        // were swapped, "filters" would be looked up as a product and 404.
        $this->getJson('/api/products/filters')
            ->assertOk()
            ->assertJsonStructure(['platforms', 'regions']);
    }

    // ---------------------------------------------------------------- offer order

    public function test_offers_are_sorted_cheapest_first(): void
    {
        // Created most expensive first, so id order disagrees with price order.
        $c = Offer::factory()->for($this->product)->price(3000)->withKeys()->create();
        $b = Offer::factory()->for($this->product)->price(2000)->withKeys()->create();
        $a = Offer::factory()->for($this->product)->price(1000)->withKeys()->create();

        $this->assertSame([$a->id, $b->id, $c->id], $this->offerIds());
    }

    public function test_same_price_is_broken_by_offer_id_like_the_store(): void
    {
        // Same price, only the first-created offer has an original price. The
        // store card picks the lower id, so the product page must show that
        // same offer first, or the store's "-20%" would not match the top row.
        $first = Offer::factory()->for($this->product)->price(2000, 2500)->withKeys()->create();
        $second = Offer::factory()->for($this->product)->price(2000)->withKeys()->create();

        $this->assertSame([$first->id, $second->id], $this->offerIds());

        $card = $this->getJson('/api/products')->json('data.0.price');
        $top = $this->getJson('/api/products/elden-ring-steam-global')->json('data.offers.0');
        $this->assertSame($card['from_cents'], $top['price_cents']);
        $this->assertSame($card['original_cents'], $top['original_price_cents']);
        $this->assertSame($card['discount_percent'], $top['discount_percent']);
    }

    public function test_the_header_price_is_the_first_offer(): void
    {
        Offer::factory()->for($this->product)->price(500)->create(); // sold out
        Offer::factory()->for($this->product)->price(1500, 3000)->withKeys()->create();
        Offer::factory()->for($this->product)->price(1800)->withKeys()->create();

        $data = $this->getJson('/api/products/elden-ring-steam-global')->json('data');

        $this->assertSame(1500, $data['price']['from_cents']);
        $this->assertSame(50, $data['price']['discount_percent']);
        $this->assertSame($data['price']['from_cents'], $data['offers'][0]['price_cents']);
    }

    public function test_sold_out_offers_come_last_even_when_cheaper(): void
    {
        $soldOut = Offer::factory()->for($this->product)->price(500)->create();       // no keys
        $inStock = Offer::factory()->for($this->product)->price(4000)->withKeys()->create();

        $this->assertSame([$inStock->id, $soldOut->id], $this->offerIds());
        $this->assertSame([1, 0], $this->offerStocks());
    }

    public function test_sold_out_offers_are_themselves_sorted_by_price(): void
    {
        $inStock = Offer::factory()->for($this->product)->price(9000)->withKeys()->create();
        $soldOutExpensive = Offer::factory()->for($this->product)->price(3000)->create();
        $soldOutCheap = Offer::factory()->for($this->product)->price(1000)->create();

        $this->assertSame([$inStock->id, $soldOutCheap->id, $soldOutExpensive->id], $this->offerIds());
    }

    // ---------------------------------------------------------------- stock

    public function test_stock_counts_only_sellable_keys(): void
    {
        $offer = Offer::factory()->for($this->product)->withKeys(2)->create();              // 2 available: counted
        ProductKey::factory()->for($offer)->sold()->create();                                // sold: not counted
        ProductKey::factory()->for($offer)->reserved(now()->addMinutes(10))->create();       // live reservation: not counted
        ProductKey::factory()->for($offer)->reserved(now()->subMinute())->create();          // expired reservation: counted (D10)

        $this->assertSame([3], $this->offerStocks());
    }

    public function test_stock_returns_when_a_reservation_expires(): void
    {
        $offer = Offer::factory()->for($this->product)->price(1000)->create();
        ProductKey::factory()->for($offer)->reserved(now()->addMinutes(15))->create();

        $this->assertSame([0], $this->offerStocks());
        $this->getJson('/api/products/elden-ring-steam-global')->assertJsonPath('data.price', null);

        // No cleanup job runs: the reservation simply lies in the past now.
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame([1], $this->offerStocks());
        $this->getJson('/api/products/elden-ring-steam-global')->assertJsonPath('data.price.from_cents', 1000);
    }

    // ---------------------------------------------------------------- edge cases

    public function test_inactive_offers_are_left_out(): void
    {
        $active = Offer::factory()->for($this->product)->price(2000)->withKeys()->create();
        Offer::factory()->for($this->product)->price(1000)->inactive()->withKeys(5)->create();

        $this->assertSame([$active->id], $this->offerIds());
    }

    public function test_product_without_offers(): void
    {
        $this->getJson('/api/products/elden-ring-steam-global')
            ->assertOk()
            ->assertJsonPath('data.offers', [])
            ->assertJsonPath('data.price', null);
    }

    public function test_product_with_only_sold_out_offers_still_lists_them(): void
    {
        Offer::factory()->for($this->product)->count(2)->create();

        $this->getJson('/api/products/elden-ring-steam-global')
            ->assertOk()
            ->assertJsonPath('data.price', null)
            ->assertJsonCount(2, 'data.offers');
    }

    public function test_offers_of_other_products_are_not_included(): void
    {
        $mine = Offer::factory()->for($this->product)->withKeys()->create();
        Offer::factory()->withKeys()->create(); // another product

        $this->assertSame([$mine->id], $this->offerIds());
    }

    public function test_key_codes_are_never_exposed(): void
    {
        $offer = Offer::factory()->for($this->product)->create();
        $key = ProductKey::factory()->for($offer)->create(['code' => 'SECRET-CODE-12345']);

        $body = $this->getJson('/api/products/elden-ring-steam-global')->assertOk()->getContent();

        $this->assertStringNotContainsString('SECRET-CODE-12345', $body);
        $this->assertStringNotContainsString($key->getRawOriginal('code'), $body); // nor the ciphertext
    }

    public function test_query_count_does_not_grow_with_offers(): void
    {
        Offer::factory()->for($this->product)->withKeys()->create();
        $few = $this->countQueries();

        Offer::factory()->for($this->product)->count(5)->withKeys(2)->create();
        $many = $this->countQueries();

        // Product (+ platform, region) and offers (+ sellers): no query per offer.
        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(5, $many);
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<int> */
    private function offerIds(): array
    {
        return $this->getJson('/api/products/elden-ring-steam-global')->assertOk()->json('data.offers.*.id');
    }

    /** @return list<int> */
    private function offerStocks(): array
    {
        return $this->getJson('/api/products/elden-ring-steam-global')->assertOk()->json('data.offers.*.stock');
    }

    private function countQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/products/elden-ring-steam-global')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
