<?php

namespace Tests\Feature\Catalog;

use App\Models\Offer;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GET /api/products: from price, filters, sorting, pagination, validation.
 * Each test builds a small explicit catalog with factories (not the demo seed),
 * so the expected result is visible in the test itself.
 */
class ProductIndexTest extends TestCase
{
    use RefreshDatabase;

    private Platform $steam;

    private Platform $xbox;

    private Region $global;

    private Region $europe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->steam = Platform::factory()->create(['name' => 'Steam', 'slug' => 'steam']);
        $this->xbox = Platform::factory()->create(['name' => 'Xbox', 'slug' => 'xbox']);
        $this->global = Region::factory()->create(['name' => 'Global', 'slug' => 'global']);
        $this->europe = Region::factory()->create(['name' => 'Europe', 'slug' => 'europe']);
    }

    // ---------------------------------------------------------------- from price

    public function test_from_price_is_the_cheapest_active_offer_with_stock(): void
    {
        $product = $this->product();
        Offer::factory()->for($product)->price(3000)->withKeys(2)->create();
        Offer::factory()->for($product)->price(2500)->withKeys(1)->create();
        Offer::factory()->for($product)->price(2000)->inactive()->withKeys(5)->create(); // inactive: ignored
        Offer::factory()->for($product)->price(1000)->create();                         // no keys: ignored

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.price.from_cents', 2500);
    }

    public function test_offers_whose_keys_are_all_sold_are_not_in_stock(): void
    {
        $product = $this->product();
        $soldOut = Offer::factory()->for($product)->price(1000)->create();
        ProductKey::factory()->for($soldOut)->sold()->create();
        Offer::factory()->for($product)->price(1500)->withKeys(1)->create();

        $this->getJson('/api/products')->assertJsonPath('data.0.price.from_cents', 1500);
    }

    public function test_an_active_reservation_removes_stock_and_an_expired_one_returns_it(): void
    {
        $product = $this->product();
        $reserved = Offer::factory()->for($product)->price(1000)->create();
        ProductKey::factory()->for($reserved)->reserved(now()->addMinutes(15))->create();
        Offer::factory()->for($product)->price(1500)->withKeys(1)->create();

        // Reserved by someone else's pending order: not sellable.
        $this->getJson('/api/products')->assertJsonPath('data.0.price.from_cents', 1500);

        // 16 minutes later the reservation has expired: the key counts as stock again,
        // without any cleanup having run (D10).
        Carbon::setTestNow(now()->addMinutes(16));
        $this->getJson('/api/products')->assertJsonPath('data.0.price.from_cents', 1000);
    }

    public function test_original_price_and_discount_come_from_the_cheapest_offer_itself(): void
    {
        $product = $this->product();
        // A bigger discount on a more expensive offer must not be mixed in.
        Offer::factory()->for($product)->price(3000, 9000)->withKeys(1)->create();
        Offer::factory()->for($product)->price(2000, 2999)->withKeys(1)->create();

        $this->getJson('/api/products')
            ->assertJsonPath('data.0.price', [
                'from_cents' => 2000,
                'original_cents' => 2999,
                'discount_percent' => 33, // 33.3% rounded down
                'currency' => 'EUR',
            ]);
    }

    public function test_out_of_stock_product_is_listed_with_null_price(): void
    {
        Offer::factory()->for($this->product())->price(1000)->create(); // no keys

        $this->getJson('/api/products')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price', null);
    }

    public function test_out_of_stock_products_are_last_in_both_price_sorts(): void
    {
        $outOfStock = $this->product();
        $cheap = $this->productWithPrice(1000);
        $expensive = $this->productWithPrice(5000);

        $this->assertSame([$cheap->id, $expensive->id, $outOfStock->id], $this->ids('sort=price_asc'));
        $this->assertSame([$expensive->id, $cheap->id, $outOfStock->id], $this->ids('sort=price_desc'));
    }

    // ---------------------------------------------------------------- filters

    public function test_platforms_combine_with_or_and_platform_with_region_combines_with_and(): void
    {
        $steamGlobal = $this->productWithPrice(1000, $this->steam, $this->global);
        $steamEurope = $this->productWithPrice(1000, $this->steam, $this->europe);
        $xboxEurope = $this->productWithPrice(1000, $this->xbox, $this->europe);
        $other = $this->productWithPrice(1000, Platform::factory()->create(), $this->global);

        $this->assertEqualsCanonicalizing(
            [$steamGlobal->id, $steamEurope->id, $xboxEurope->id],
            $this->ids('platform[]=steam&platform[]=xbox'),
        );
        $this->assertEqualsCanonicalizing(
            [$steamEurope->id, $xboxEurope->id],
            $this->ids('platform[]=steam&platform[]=xbox&region[]=europe'),
        );
        $this->assertNotContains($other->id, $this->ids('platform[]=steam'));
    }

    public function test_unknown_slug_returns_an_empty_list_not_an_error(): void
    {
        $this->productWithPrice(1000);

        $this->getJson('/api/products?platform[]=no-such-platform')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_price_bounds_are_inclusive_and_exclude_out_of_stock(): void
    {
        $this->productWithPrice(999);
        $atMin = $this->productWithPrice(1000);
        $atMax = $this->productWithPrice(2000);
        $this->productWithPrice(2001);
        $this->product(); // out of stock

        $this->assertEqualsCanonicalizing([$atMin->id, $atMax->id], $this->ids('price_min=1000&price_max=2000'));
    }

    public function test_price_max_alone_is_accepted(): void
    {
        $cheap = $this->productWithPrice(500);
        $this->productWithPrice(5000);

        $this->assertSame([$cheap->id], $this->ids('price_max=1000'));
    }

    // ---------------------------------------------------------------- sorting

    public function test_newest_is_the_default_and_puts_products_without_release_date_last(): void
    {
        $noDate = $this->productWithPrice(1000, release: null);
        $old = $this->productWithPrice(1000, release: '2015-01-01');
        $new = $this->productWithPrice(1000, release: '2024-01-01');

        $this->assertSame([$new->id, $old->id, $noDate->id], $this->ids());
    }

    public function test_name_sort(): void
    {
        $b = $this->productWithPrice(1000, name: 'Beta');
        $a = $this->productWithPrice(1000, name: 'Alpha');

        $this->assertSame([$a->id, $b->id], $this->ids('sort=name'));
    }

    public function test_paging_through_equal_prices_returns_every_product_exactly_once(): void
    {
        // 7 products with the same price: without the id tie-breaker their order
        // is not guaranteed between the separate page queries.
        $expected = collect(range(1, 7))->map(fn () => $this->productWithPrice(1000)->id)->sort()->values()->all();

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $seen = [...$seen, ...$this->ids("sort=price_asc&per_page=3&page={$page}")];
        }

        $this->assertSame($expected, $seen);
    }

    // ---------------------------------------------------------------- pagination & validation

    public function test_pagination_meta_and_default_page_size(): void
    {
        foreach (range(1, 25) as $i) {
            $this->productWithPrice(1000);
        }

        $this->getJson('/api/products?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 24)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_total_matches_the_list_with_and_without_a_price_filter(): void
    {
        // The total is counted by a separate query (ProductQuery::count) that skips the
        // from-price join unless a price filter is set. Both paths must agree with the list.
        $this->productWithPrice(500);
        $this->productWithPrice(1500);
        $this->productWithPrice(1500, $this->xbox);
        $this->product(); // out of stock: counted without a price filter, excluded with one

        $this->getJson('/api/products')->assertJsonPath('meta.total', 4);
        $this->getJson('/api/products?price_min=1000')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/products?price_min=1000&platform[]=steam')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/products?platform[]=xbox')->assertJsonPath('meta.total', 1);
    }

    public function test_pagination_links_keep_the_filters(): void
    {
        foreach (range(1, 3) as $i) {
            $this->productWithPrice(1000);
        }

        $next = $this->getJson('/api/products?platform[]=steam&per_page=2')->json('links.next');

        $this->assertStringContainsString('platform', urldecode($next));
        $this->assertStringContainsString('page=2', $next);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidQueries(): array
    {
        return [
            'unknown sort' => ['sort=cheapest', 'sort'],
            'negative price' => ['price_min=-1', 'price_min'],
            'min above max' => ['price_min=2000&price_max=1000', 'price_max'],
            'non-integer price' => ['price_min=9.99', 'price_min'],
            'per_page above max' => ['per_page=61', 'per_page'],
            'page zero' => ['page=0', 'page'],
            'platform not an array' => ['platform=steam', 'platform'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_query_returns_422(string $query, string $field): void
    {
        $this->getJson("/api/products?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_query_count_does_not_grow_with_page_size(): void
    {
        foreach (range(1, 20) as $i) {
            $this->productWithPrice(1000);
        }

        $this->assertSame($this->countQueries('per_page=2'), $this->countQueries('per_page=20'));
    }

    // ---------------------------------------------------------------- helpers

    private function product(?Platform $platform = null, ?Region $region = null, ?string $release = '2020-01-01', ?string $name = null): Product
    {
        return Product::factory()
            ->for($platform ?? $this->steam)
            ->for($region ?? $this->global)
            ->create(['release_date' => $release] + ($name ? ['name' => $name] : []));
    }

    private function productWithPrice(int $cents, ?Platform $platform = null, ?Region $region = null, ?string $release = '2020-01-01', ?string $name = null): Product
    {
        $product = $this->product($platform, $region, $release, $name);
        Offer::factory()->for($product)->price($cents)->withKeys(1)->create();

        return $product;
    }

    /** @return list<int> product ids in response order */
    private function ids(string $query = ''): array
    {
        return $this->getJson("/api/products?{$query}")->assertOk()->json('data.*.id');
    }

    private function countQueries(string $query): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/products?{$query}")->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
