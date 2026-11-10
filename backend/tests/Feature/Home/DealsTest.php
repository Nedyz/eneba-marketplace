<?php

namespace Tests\Feature\Home;

use App\Models\Offer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/home/deals (D32): products whose card price (the from-price offer,
 * D23) is discounted, largest discount first, 12 at most.
 */
class DealsTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $slug): Product
    {
        return Product::factory()->create(['slug' => $slug]);
    }

    /** @return list<string> */
    private function slugs(): array
    {
        return $this->getJson('/api/home/deals')->assertOk()->json('data.*.slug');
    }

    public function test_returns_store_cards_with_the_discount(): void
    {
        Offer::factory()->for($this->product('hades'))->price(1999, 2500)->withKeys()->create();

        $this->getJson('/api/home/deals')
            ->assertJsonPath('data.0.slug', 'hades')
            ->assertJsonPath('data.0.price', ['from_cents' => 1999, 'original_cents' => 2500, 'discount_percent' => 20, 'currency' => 'EUR']);
    }

    public function test_largest_discount_first(): void
    {
        // Created in an order that matches neither the expected order nor its reverse.
        Offer::factory()->for($this->product('minus-25'))->price(1500, 2000)->withKeys()->create();
        Offer::factory()->for($this->product('minus-10'))->price(1800, 2000)->withKeys()->create();
        Offer::factory()->for($this->product('minus-40'))->price(1200, 2000)->withKeys()->create();

        $this->assertSame(['minus-40', 'minus-25', 'minus-10'], $this->slugs());
    }

    public function test_ranks_by_percent_not_by_amount_saved(): void
    {
        // -€10 on €100 (10%) saves more money than -€5 on €10 (50%).
        Offer::factory()->for($this->product('ten-off-a-hundred'))->price(9000, 10000)->withKeys()->create();
        Offer::factory()->for($this->product('five-off-ten'))->price(500, 1000)->withKeys()->create();

        $this->assertSame(['five-off-ten', 'ten-off-a-hundred'], $this->slugs());
    }

    public function test_products_without_a_discount_are_left_out(): void
    {
        Offer::factory()->for($this->product('full-price'))->price(1999)->withKeys()->create();
        Offer::factory()->for($this->product('deal'))->price(1999, 2999)->withKeys()->create();

        $this->assertSame(['deal'], $this->slugs());
    }

    public function test_a_discount_on_an_offer_that_is_not_the_card_price_does_not_count(): void
    {
        // The card shows the cheapest offer (€10, no discount), so the -50% offer
        // from another seller must not put this product among the deals.
        $product = $this->product('cheaper-elsewhere');
        Offer::factory()->for($product)->price(1000)->withKeys()->create();
        Offer::factory()->for($product)->price(1500, 3000)->withKeys()->create();

        $this->assertSame([], $this->slugs());
    }

    public function test_a_sold_out_discounted_offer_does_not_count(): void
    {
        // Sold out -75%: not the card price. The in-stock -10% is.
        $product = $this->product('sold-out-deal');
        Offer::factory()->for($product)->price(1000, 4000)->create(); // no keys
        Offer::factory()->for($product)->price(1800, 2000)->withKeys()->create();
        Offer::factory()->for($this->product('minus-20'))->price(1600, 2000)->withKeys()->create();

        $this->assertSame(['minus-20', 'sold-out-deal'], $this->slugs());
        $this->getJson('/api/home/deals')->assertJsonPath('data.1.price.discount_percent', 10);
    }

    public function test_products_with_nothing_in_stock_are_left_out(): void
    {
        Offer::factory()->for($this->product('sold-out'))->price(1000, 4000)->create();

        $this->assertSame([], $this->slugs());
    }

    public function test_at_most_12_products_and_the_smallest_discounts_drop_out(): void
    {
        foreach (range(1, 13) as $i) {
            // game-1 has the smallest discount (1%), game-13 the largest (13%).
            Offer::factory()->for($this->product("game-{$i}"))->price(10000 - $i * 100, 10000)->withKeys()->create();
        }

        $slugs = $this->slugs();
        $this->assertCount(12, $slugs);
        $this->assertNotContains('game-1', $slugs);
    }
}
