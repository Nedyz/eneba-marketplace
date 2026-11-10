<?php

namespace Tests\Feature\Cart;

use App\Models\Offer;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductKey;
use App\Models\Region;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * POST /api/cart/quote (D31): the server prices the browser's cart. Prices,
 * stock and the total always come from the database; the request carries only
 * offer ids and quantities.
 */
class CartQuoteTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = Product::factory()
            ->for(Platform::factory()->create(['name' => 'Steam', 'slug' => 'steam']))
            ->for(Region::factory()->create(['name' => 'Global', 'slug' => 'global']))
            ->create(['slug' => 'elden-ring-steam-global', 'name' => 'Elden Ring']);
    }

    // ---------------------------------------------------------------- shape and prices

    public function test_line_shape(): void
    {
        $seller = Seller::factory()->create(['name' => 'KeyHub', 'rating' => 4.85]);
        $offer = Offer::factory()->for($this->product)->for($seller)->price(1999, 2500)->withKeys(5)->create();

        $this->quote([[$offer->id, 2]])
            ->assertOk()
            ->assertExactJson(['data' => [
                'lines' => [[
                    'offer_id' => $offer->id,
                    'qty' => 2,
                    'status' => 'available',
                    'stock' => 5,
                    'unit_price_cents' => 1999,
                    'original_price_cents' => 2500,
                    'discount_percent' => 20,
                    'line_total_cents' => 3998,
                    'product' => [
                        'slug' => 'elden-ring-steam-global',
                        'name' => 'Elden Ring',
                        'image_url' => null,
                        'platform' => ['slug' => 'steam', 'name' => 'Steam'],
                        'region' => ['slug' => 'global', 'name' => 'Global'],
                    ],
                    'seller' => ['id' => $seller->id, 'name' => 'KeyHub', 'rating' => 4.85],
                ]],
                'total_cents' => 3998,
                'currency' => 'EUR',
                'can_checkout' => true,
            ]]);
    }

    public function test_prices_sent_by_the_client_are_ignored(): void
    {
        $offer = Offer::factory()->for($this->product)->price(1999)->withKeys()->create();

        $this->postJson('/api/cart/quote', [
            'lines' => [['offer_id' => $offer->id, 'qty' => 1, 'price_cents' => 1, 'unit_price_cents' => 1]],
            'total_cents' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.unit_price_cents', 1999)
            ->assertJsonPath('data.total_cents', 1999);
    }

    public function test_the_current_price_is_quoted_after_a_change(): void
    {
        $offer = Offer::factory()->for($this->product)->price(1999)->withKeys()->create();
        $this->quote([[$offer->id, 1]])->assertJsonPath('data.total_cents', 1999);

        $offer->update(['price_cents' => 2499]);

        $this->quote([[$offer->id, 1]])
            ->assertJsonPath('data.lines.0.unit_price_cents', 2499)
            ->assertJsonPath('data.total_cents', 2499);
    }

    public function test_total_sums_the_available_lines(): void
    {
        $a = Offer::factory()->for($this->product)->price(1000)->withKeys(3)->create();
        $b = Offer::factory()->for($this->product)->price(250)->withKeys(3)->create();

        $this->quote([[$a->id, 2], [$b->id, 3]])
            ->assertJsonPath('data.lines.0.line_total_cents', 2000)
            ->assertJsonPath('data.lines.1.line_total_cents', 750)
            ->assertJsonPath('data.total_cents', 2750)
            ->assertJsonPath('data.can_checkout', true);
    }

    public function test_lines_keep_the_request_order(): void
    {
        // Created in the opposite order, so id order disagrees with request order.
        $second = Offer::factory()->for($this->product)->withKeys()->create();
        $first = Offer::factory()->for($this->product)->withKeys()->create();

        $ids = array_column($this->quote([[$first->id, 1], [$second->id, 1]])->json('data.lines'), 'offer_id');

        $this->assertSame([$first->id, $second->id], $ids);
    }

    // ---------------------------------------------------------------- statuses

    public function test_quantity_equal_to_stock_is_available(): void
    {
        $offer = Offer::factory()->for($this->product)->price(1000)->withKeys(2)->create();

        $this->quote([[$offer->id, 2]])
            ->assertJsonPath('data.lines.0.status', 'available')
            ->assertJsonPath('data.can_checkout', true);
    }

    public function test_quantity_above_stock_is_insufficient_and_not_counted(): void
    {
        $limited = Offer::factory()->for($this->product)->price(1000)->withKeys(2)->create();
        $fine = Offer::factory()->for($this->product)->price(500)->withKeys(1)->create();

        $this->quote([[$limited->id, 3], [$fine->id, 1]])
            ->assertJsonPath('data.lines.0.status', 'insufficient_stock')
            ->assertJsonPath('data.lines.0.stock', 2)
            ->assertJsonPath('data.lines.0.unit_price_cents', 1000) // still shown
            ->assertJsonPath('data.lines.0.line_total_cents', null)
            ->assertJsonPath('data.total_cents', 500)
            ->assertJsonPath('data.can_checkout', false);
    }

    public function test_offer_without_sellable_keys_is_sold_out(): void
    {
        $offer = Offer::factory()->for($this->product)->price(1000)->create(); // no keys
        ProductKey::factory()->for($offer)->sold()->create();

        $this->quote([[$offer->id, 1]])
            ->assertJsonPath('data.lines.0.status', 'sold_out')
            ->assertJsonPath('data.lines.0.stock', 0)
            ->assertJsonPath('data.lines.0.unit_price_cents', 1000)
            ->assertJsonPath('data.lines.0.line_total_cents', null)
            ->assertJsonPath('data.total_cents', 0)
            ->assertJsonPath('data.can_checkout', false);
    }

    public function test_stock_uses_the_sellable_rule(): void
    {
        $offer = Offer::factory()->for($this->product)->price(1000)->withKeys(1)->create();
        ProductKey::factory()->for($offer)->sold()->create();                          // not counted
        ProductKey::factory()->for($offer)->reserved(now()->addMinutes(10))->create(); // live reservation: not counted
        ProductKey::factory()->for($offer)->reserved(now()->subMinute())->create();    // expired reservation: counted (D10)

        $this->quote([[$offer->id, 1]])->assertJsonPath('data.lines.0.stock', 2);
    }

    public function test_line_comes_back_when_a_reservation_expires(): void
    {
        $offer = Offer::factory()->for($this->product)->price(1000)->create();
        ProductKey::factory()->for($offer)->reserved(now()->addMinutes(15))->create();

        $this->quote([[$offer->id, 1]])->assertJsonPath('data.lines.0.status', 'sold_out');

        // No cleanup job: the reservation simply lies in the past now. The cart
        // was not changed by the sold-out quote, so the line is buyable again.
        Carbon::setTestNow(now()->addMinutes(16));
        $this->quote([[$offer->id, 1]])
            ->assertJsonPath('data.lines.0.status', 'available')
            ->assertJsonPath('data.total_cents', 1000);
    }

    public function test_withdrawn_offer_is_unavailable_without_a_price(): void
    {
        $offer = Offer::factory()->for($this->product)->price(1000, 2000)->inactive()->withKeys(5)->create();

        $this->quote([[$offer->id, 1]])
            ->assertJsonPath('data.lines.0.status', 'unavailable')
            ->assertJsonPath('data.lines.0.stock', 0)
            ->assertJsonPath('data.lines.0.unit_price_cents', null)
            ->assertJsonPath('data.lines.0.original_price_cents', null)
            ->assertJsonPath('data.lines.0.discount_percent', null)
            ->assertJsonPath('data.lines.0.line_total_cents', null)
            // Still described, so the cart can link to the product's other sellers.
            ->assertJsonPath('data.lines.0.product.slug', 'elden-ring-steam-global')
            ->assertJsonPath('data.total_cents', 0)
            ->assertJsonPath('data.can_checkout', false);
    }

    public function test_unknown_offer_is_unavailable_not_an_error(): void
    {
        $offer = Offer::factory()->for($this->product)->price(1000)->withKeys()->create();

        $this->quote([[$offer->id + 1000, 1], [$offer->id, 1]])
            ->assertOk()
            ->assertJsonPath('data.lines.0.offer_id', $offer->id + 1000)
            ->assertJsonPath('data.lines.0.status', 'unavailable')
            ->assertJsonPath('data.lines.0.product', null)
            ->assertJsonPath('data.lines.0.seller', null)
            ->assertJsonPath('data.lines.1.status', 'available')
            ->assertJsonPath('data.total_cents', 1000)
            ->assertJsonPath('data.can_checkout', false);
    }

    public function test_quoting_reserves_nothing(): void
    {
        $offer = Offer::factory()->for($this->product)->withKeys(2)->create();

        $this->quote([[$offer->id, 2]]);

        $this->assertSame(2, ProductKey::where('offer_id', $offer->id)->where('status', 'available')->count());
    }

    // ---------------------------------------------------------------- performance

    public function test_query_count_does_not_grow_with_lines(): void
    {
        $one = Offer::factory()->for($this->product)->withKeys()->create();
        $few = Offer::factory()->count(5)->withKeys()->create(); // different products and sellers

        $single = $this->countQueries(fn () => $this->quote([[$one->id, 1]]));
        $many = $this->countQueries(fn () => $this->quote($few->map(fn (Offer $o) => [$o->id, 1])->all()));

        $this->assertSame($single, $many);
    }

    // ---------------------------------------------------------------- validation

    /** @return array<string, array{mixed, string}> */
    public static function invalidBodies(): array
    {
        return [
            'missing lines' => [[], 'lines'],
            'empty lines' => [['lines' => []], 'lines'],
            'lines is an object' => [['lines' => ['a' => ['offer_id' => 1, 'qty' => 1]]], 'lines'],
            'too many lines' => [['lines' => array_map(fn (int $i) => ['offer_id' => $i, 'qty' => 1], range(1, 51))], 'lines'],
            'line is not an object' => [['lines' => [5]], 'lines.0'],
            'missing offer id' => [['lines' => [['qty' => 1]]], 'lines.0.offer_id'],
            'offer id not an integer' => [['lines' => [['offer_id' => 'abc', 'qty' => 1]]], 'lines.0.offer_id'],
            'offer id zero' => [['lines' => [['offer_id' => 0, 'qty' => 1]]], 'lines.0.offer_id'],
            'duplicate offer id' => [['lines' => [['offer_id' => 1, 'qty' => 1], ['offer_id' => 1, 'qty' => 2]]], 'lines.0.offer_id'],
            'missing qty' => [['lines' => [['offer_id' => 1]]], 'lines.0.qty'],
            'qty zero' => [['lines' => [['offer_id' => 1, 'qty' => 0]]], 'lines.0.qty'],
            'qty above 10' => [['lines' => [['offer_id' => 1, 'qty' => 11]]], 'lines.0.qty'],
            'qty not whole' => [['lines' => [['offer_id' => 1, 'qty' => 1.5]]], 'lines.0.qty'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_body_is_rejected(array $body, string $field): void
    {
        $this->postJson('/api/cart/quote', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_fifty_lines_of_ten_are_accepted(): void
    {
        $lines = array_map(fn (int $i) => [$i, 10], range(1, 50));

        $this->quote($lines)->assertOk()->assertJsonCount(50, 'data.lines');
    }

    // ---------------------------------------------------------------- helpers

    /** @param  list<array{int, int}>  $lines  [offer id, qty] pairs */
    private function quote(array $lines): TestResponse
    {
        return $this->postJson('/api/cart/quote', [
            'lines' => array_map(fn (array $l) => ['offer_id' => $l[0], 'qty' => $l[1]], $lines),
        ]);
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request()->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
