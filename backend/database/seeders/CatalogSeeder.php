<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Platforms, regions, sellers, products and offers from data/catalog.php.
 *
 * Randomness uses a seeded generator, so every run (local, CI, production)
 * produces exactly the same prices, deals and stock. No Faker: production is
 * installed with `composer install --no-dev`, where Faker does not exist.
 *
 * Bulk inserts (one query per table) instead of Model::create() per row: against
 * a remote database (Neon) each query is a network round trip.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = require __DIR__.'/data/catalog.php';
        $rng = new Randomizer(new Mt19937(42));
        $now = now();
        $stamps = ['created_at' => $now, 'updated_at' => $now];

        // Platforms and regions, then read back slug => id.
        foreach (['platforms', 'regions'] as $table) {
            DB::table($table)->insert(array_map(
                fn ($slug, $name) => ['slug' => $slug, 'name' => $name] + $stamps,
                array_keys($catalog[$table]),
                $catalog[$table],
            ));
        }
        $platformIds = DB::table('platforms')->pluck('id', 'slug');
        $regionIds = DB::table('regions')->pluck('id', 'slug');

        DB::table('sellers')->insert(array_map(fn ($s) => $s + $stamps, $catalog['sellers']));
        $sellerIds = DB::table('sellers')->orderBy('id')->pluck('id')->all();

        // One product per edition (game + platform + region).
        $products = [];
        foreach ($catalog['games'] as $game) {
            foreach ($game['editions'] as [$platform, $region]) {
                $products[] = [
                    'slug' => Str::slug("{$game['name']} {$platform} {$region}"),
                    'name' => $game['name'],
                    'description' => $game['description'],
                    'image_url' => null, // frontend draws a generated cover (D15)
                    'platform_id' => $platformIds[$platform],
                    'region_id' => $regionIds[$region],
                    'release_date' => $game['release_date'],
                ] + $stamps;
            }
        }
        DB::table('products')->insert($products);

        // Base price per product, to vary offers around it.
        $basePrices = [];
        foreach ($catalog['games'] as $game) {
            $basePrices[$game['name']] = $game['base_price_cents'];
        }

        // 2-5 offers per product from different sellers.
        $offers = [];
        foreach (DB::table('products')->orderBy('id')->get(['id', 'name']) as $product) {
            $sellers = array_slice($rng->shuffleArray($sellerIds), 0, $rng->getInt(2, 5));
            foreach ($sellers as $sellerId) {
                // +-15% around the base price, ending in .99 like real shop prices.
                $price = self::endIn99((int) round($basePrices[$product->name] * $rng->getFloat(0.85, 1.15)));
                // ~30% of offers are deals: the original price is 15-60% higher.
                $original = $rng->getInt(1, 100) <= 30
                    ? self::endIn99((int) round($price * $rng->getFloat(1.15, 1.6)))
                    : null;

                $offers[] = [
                    'product_id' => $product->id,
                    'seller_id' => $sellerId,
                    'price_cents' => $price,
                    'original_price_cents' => $original,
                    // ~5% inactive: the store must ignore them.
                    'is_active' => $rng->getInt(1, 100) > 5,
                ] + $stamps;
            }
        }
        DB::table('offers')->insert($offers);
    }

    /** 3412 -> 3499. Always rounds up, so a higher input never ends lower. */
    private static function endIn99(int $cents): int
    {
        return intdiv($cents, 100) * 100 + 99;
    }
}
