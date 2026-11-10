<?php

namespace Tests\Feature\Catalog;

use App\Models\Offer;
use App\Models\Platform;
use App\Models\Product;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/products?q=: typo-tolerant search and relevance sort (DECISIONS.md D28).
 *
 * Every product name is set explicitly: factory names are random words, which
 * could fuzzy-match a search term by accident and make a test flaky.
 */
class ProductSearchTest extends TestCase
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

    // ---------------------------------------------------------------- matching

    public function test_typos_find_the_intended_game(): void
    {
        $witcher = $this->product('The Witcher 3: Wild Hunt');
        $elden = $this->product('Elden Ring');
        $cyberpunk = $this->product('Cyberpunk 2077');
        $this->product('Hollow Knight');

        $this->assertSame([$witcher->id], $this->ids('q=witcer'));
        $this->assertSame([$elden->id], $this->ids('q=eldn ring'));
        $this->assertSame([$cyberpunk->id], $this->ids('q=cyberpnk'));
    }

    public function test_partial_names_match_anywhere_in_a_long_title(): void
    {
        // similarity('zelda', name) is only 0.18 here; word_similarity is 1.0.
        $zelda = $this->product('The Legend of Zelda: Tears of the Kingdom');
        $elden = $this->product('Elden Ring');
        $this->product('Hollow Knight');

        $this->assertSame([$zelda->id], $this->ids('q=zelda'));
        // Half-typed word, as in autocomplete. "Zelda" contains "eld" too (substring
        // match); Elden Ring starts with it, so it comes first.
        $this->assertSame([$elden->id, $zelda->id], $this->ids('q=eld'));
    }

    public function test_the_lowered_threshold_is_applied(): void
    {
        // word_similarity('pokemon', 'Pokémon Scarlet') = 0.45: found at our 0.4,
        // missed at pg_trgm's default 0.6. Fails if the threshold is not set.
        $pokemon = $this->product('Pokémon Scarlet');

        $this->assertSame([$pokemon->id], $this->ids('q=pokemon'));
    }

    public function test_unrelated_query_returns_an_empty_list(): void
    {
        $this->product('Elden Ring');

        $this->getJson('/api/products?q=spreadsheet')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_like_wildcards_in_the_query_are_literal(): void
    {
        $this->product('Elden Ring');
        $this->product('Hollow Knight');
        $percent = $this->product('100% Orange Juice');

        // Unescaped, '%' and '_' would match every name through ILIKE.
        $this->assertSame([], $this->ids('q='.urlencode('_')));
        $this->assertSame([$percent->id], $this->ids('q='.urlencode('100%')));
    }

    public function test_search_combines_with_filters(): void
    {
        $onSteam = $this->product('Elden Ring', $this->steam, priceCents: 3000);
        $this->product('Elden Ring', $this->xbox, priceCents: 3000);
        $this->product('Elden Ring', $this->steam, priceCents: 9000);
        $this->product('Hollow Knight', $this->steam, priceCents: 1000);

        $this->assertSame([$onSteam->id], $this->ids('q=elden&platform[]=steam&price_max=5000'));
    }

    public function test_total_matches_the_list(): void
    {
        foreach (['Elden Ring', 'Elden Ring', 'Dark Souls III', 'Hollow Knight'] as $name) {
            $this->product($name, priceCents: 2000);
        }

        $this->getJson('/api/products?q=elden')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/products?q=elden&price_min=1000')->assertJsonPath('meta.total', 2); // count with price join
    }

    public function test_whitespace_is_trimmed_and_collapsed(): void
    {
        $elden = $this->product('Elden Ring');

        $this->assertSame([$elden->id], $this->ids('q='.urlencode('  elden    ring ')));
    }

    public function test_empty_query_is_ignored(): void
    {
        $a = $this->product('Elden Ring');
        $b = $this->product('Hollow Knight');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids('q='));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids('q='.urlencode('   ')));
    }

    public function test_query_longer_than_100_characters_is_rejected(): void
    {
        $this->getJson('/api/products?q='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    // ---------------------------------------------------------------- ranking

    public function test_names_starting_with_the_query_come_first(): void
    {
        // Both have word_similarity 1.0 for "mario". Dr. Mario World wins on every
        // later tie-breaker (similarity 0.40 vs 0.26, name, id), so only the prefix
        // rule can put Mario Party first.
        $dr = $this->product('Dr. Mario World');
        $party = $this->product('Mario Party Superstars');

        $this->assertSame([$party->id, $dr->id], $this->ids('q=mario'));
    }

    public function test_the_intended_game_ranks_above_fuzzy_noise(): void
    {
        // "stardew" also reaches 0.5 on Starfield (shared "star"); Stardew Valley is 1.0.
        $starfield = $this->product('Starfield');
        $stardew = $this->product('Stardew Valley');

        $this->assertSame([$stardew->id, $starfield->id], $this->ids('q=stardew'));
    }

    public function test_without_a_prefix_match_the_higher_word_similarity_wins(): void
    {
        // Neither starts with "ring". word_similarity: 1.0 for the long title (whole
        // word), 0.6 for Spring (inside a longer word). Spring wins on every later
        // tie-breaker (similarity 0.33 vs 0.13, name, id), so only word_similarity
        // can put the long title first.
        $spring = $this->product('Spring');
        $theRing = $this->product('The Ring of Elden Lords Deluxe Edition');

        $this->assertSame([$theRing->id, $spring->id], $this->ids('q=ring&sort=relevance'));
    }

    public function test_on_equal_word_similarity_the_closer_length_wins(): void
    {
        // Both start with "mario" and score word_similarity 1.0. similarity favors
        // the shorter name (0.46 vs 0.21); name order would put "Mario and..." first.
        $luigi = $this->product('Mario and Luigi: Brothership');
        $tennis = $this->product('Mario Tennis');

        $this->assertSame([$tennis->id, $luigi->id], $this->ids('q=mario'));
    }

    public function test_equal_relevance_is_ordered_by_id(): void
    {
        $createdFirst = $this->product('Elden Ring', $this->xbox);
        $createdSecond = $this->product('Elden Ring', $this->steam);

        // Two editions of the same game score the same on everything but id,
        // so offset pages can never overlap or skip one (D24).
        $this->assertSame([$createdFirst->id, $createdSecond->id], $this->ids('q=elden ring'));
    }

    // ---------------------------------------------------------------- sort selection

    public function test_relevance_is_the_default_sort_when_searching(): void
    {
        // Newest would put Dr. Mario World first; relevance puts the prefix match first.
        $relevant = $this->product('Mario Party Superstars', release: '2019-07-10');
        $newest = $this->product('Dr. Mario World', release: '2021-10-29');

        $this->assertSame([$relevant->id, $newest->id], $this->ids('q=mario'));
    }

    public function test_an_explicit_sort_overrides_relevance(): void
    {
        $cheap = $this->product('Super Mario Odyssey', priceCents: 1000);
        $expensive = $this->product('Mario Kart 8 Deluxe', priceCents: 5000);

        $this->assertSame([$cheap->id, $expensive->id], $this->ids('q=mario&sort=price_asc'));
    }

    public function test_relevance_without_a_query_falls_back_to_newest(): void
    {
        // A shared URL can keep sort=relevance after the search box is cleared.
        $old = $this->product('Hollow Knight', release: '2017-02-24');
        $new = $this->product('Elden Ring', release: '2022-02-25');

        $this->assertSame([$new->id, $old->id], $this->ids('sort=relevance'));
    }

    // ---------------------------------------------------------------- helpers

    private function product(string $name, ?Platform $platform = null, ?int $priceCents = null, ?string $release = '2020-01-01'): Product
    {
        $product = Product::factory()
            ->for($platform ?? $this->steam)
            ->for($this->global)
            ->create(['name' => $name, 'release_date' => $release]);

        if ($priceCents !== null) {
            Offer::factory()->for($product)->price($priceCents)->withKeys(1)->create();
        }

        return $product;
    }

    /** @return list<int> product ids in response order */
    private function ids(string $query): array
    {
        return $this->getJson("/api/products?{$query}")->assertOk()->json('data.*.id');
    }
}
