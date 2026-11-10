<?php

namespace Tests\Feature\Catalog;

use App\Models\Offer;
use App\Models\Platform;
use App\Models\Product;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/search/suggestions?q=: header autocomplete. Same matching and ranking
 * as the store search; one row per edition (game + platform + region), as on Eneba.
 */
class SearchSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private Platform $steam;

    private Platform $xbox;

    private Region $global;

    protected function setUp(): void
    {
        parent::setUp();
        $this->steam = Platform::factory()->create(['name' => 'Steam', 'slug' => 'steam']);
        $this->xbox = Platform::factory()->create(['name' => 'Xbox', 'slug' => 'xbox']);
        $this->global = Region::factory()->create(['name' => 'Global', 'slug' => 'global']);
    }

    public function test_returns_editions_with_platform_region_and_from_price(): void
    {
        $product = $this->product('Elden Ring', $this->steam);
        Offer::factory()->for($product)->price(2999)->withKeys(1)->create();

        $this->getJson('/api/search/suggestions?q=eldn')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $product->slug)
            ->assertJsonPath('data.0.name', 'Elden Ring')
            ->assertJsonPath('data.0.platform.slug', 'steam')
            ->assertJsonPath('data.0.region.slug', 'global')
            ->assertJsonPath('data.0.price.from_cents', 2999);
    }

    public function test_the_lowered_threshold_is_applied(): void
    {
        // word_similarity('pokemon', 'Pokémon Scarlet') = 0.45: found at our 0.4,
        // missed at pg_trgm's default 0.6. Fails if this endpoint ever runs its
        // query outside ProductSearch::withThreshold().
        $pokemon = $this->product('Pokémon Scarlet', $this->steam);

        $this->assertSame(
            [$pokemon->id],
            $this->getJson('/api/search/suggestions?q=pokemon')->assertOk()->json('data.*.id'),
        );
    }

    public function test_each_edition_is_its_own_row_ranked_like_the_store(): void
    {
        $odyssey = $this->product('Super Mario Odyssey', $this->steam);
        $kartSteam = $this->product('Mario Kart 8 Deluxe', $this->steam);
        $kartXbox = $this->product('Mario Kart 8 Deluxe', $this->xbox);

        $this->assertSame(
            [$kartSteam->id, $kartXbox->id, $odyssey->id],
            $this->getJson('/api/search/suggestions?q=mario')->assertOk()->json('data.*.id'),
        );
    }

    public function test_out_of_stock_products_are_suggested_with_null_price(): void
    {
        $this->product('Elden Ring', $this->steam);

        $this->getJson('/api/search/suggestions?q=elden')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price', null);
    }

    public function test_returns_at_most_eight(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->product("Elden Ring {$i}", $this->steam);
        }

        $this->getJson('/api/search/suggestions?q=elden')->assertOk()->assertJsonCount(8, 'data');
    }

    public function test_no_match_returns_an_empty_list(): void
    {
        $this->product('Elden Ring', $this->steam);

        $this->getJson('/api/search/suggestions?q=spreadsheet')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_query_must_be_2_to_100_characters(): void
    {
        $this->getJson('/api/search/suggestions')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/search/suggestions?q=e')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/search/suggestions?q='.urlencode(' e '))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/search/suggestions?q='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/search/suggestions?q=el')->assertOk();
    }

    private function product(string $name, Platform $platform): Product
    {
        return Product::factory()->for($platform)->for($this->global)->create(['name' => $name]);
    }
}
