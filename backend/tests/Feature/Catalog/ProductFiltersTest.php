<?php

namespace Tests\Feature\Catalog;

use App\Models\Offer;
use App\Models\Platform;
use App\Models\Product;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/products/filters: options for the store's filter panel.
 */
class ProductFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_platforms_and_regions_that_have_products_sorted_by_name(): void
    {
        $xbox = Platform::factory()->create(['name' => 'Xbox', 'slug' => 'xbox']);
        $steam = Platform::factory()->create(['name' => 'Steam', 'slug' => 'steam']);
        Platform::factory()->create(['name' => 'Unused', 'slug' => 'unused']);
        $europe = Region::factory()->create(['name' => 'Europe', 'slug' => 'europe']);
        Region::factory()->create(['name' => 'Unused', 'slug' => 'unused-region']);

        Product::factory()->for($xbox)->for($europe)->create();
        Product::factory()->for($steam)->for($europe)->create();

        $this->getJson('/api/products/filters')
            ->assertOk()
            ->assertJsonPath('platforms', [
                ['slug' => 'steam', 'name' => 'Steam'],
                ['slug' => 'xbox', 'name' => 'Xbox'],
            ])
            ->assertJsonPath('regions', [['slug' => 'europe', 'name' => 'Europe']]);
    }

    public function test_price_range_uses_from_prices_of_products_in_stock(): void
    {
        $a = Product::factory()->create();
        Offer::factory()->for($a)->price(1500)->withKeys(1)->create();
        Offer::factory()->for($a)->price(900)->withKeys(1)->create(); // a's from price: 900

        $b = Product::factory()->create();
        Offer::factory()->for($b)->price(4000)->withKeys(1)->create(); // b's from price: 4000
        Offer::factory()->for($b)->price(100)->inactive()->withKeys(1)->create();

        $c = Product::factory()->create();
        Offer::factory()->for($c)->price(50)->create(); // out of stock: ignored

        // Max is the highest FROM price (4000), not the highest offer price.
        $this->getJson('/api/products/filters')
            ->assertJsonPath('price', ['min_cents' => 900, 'max_cents' => 4000]);
    }

    public function test_empty_catalog_has_null_price_range(): void
    {
        $this->getJson('/api/products/filters')
            ->assertOk()
            ->assertJsonPath('price', ['min_cents' => null, 'max_cents' => null])
            ->assertJsonPath('platforms', []);
    }
}
