<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Fake keys (format XXXXX-XXXXX-XXXXX) for every offer, all `available`.
 *
 * Encryption: these are bulk inserts, which go straight to the query builder and
 * skip Eloquent casts. So the code is encrypted here with Crypt::encryptString(),
 * which is exactly what the model's `encrypted` cast does on save. Reading a key
 * through the ProductKey model then decrypts it normally.
 */
class ProductKeySeeder extends Seeder
{
    // No 0/O or 1/I, so a key read off the screen cannot be mistyped.
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function run(): void
    {
        $rng = new Randomizer(new Mt19937(43));
        $now = now();

        $rows = [];
        foreach (DB::table('offers')->orderBy('id')->pluck('id') as $offerId) {
            // ~10% of offers are out of stock (0 keys): the store and product page
            // must then use the next cheapest offer that has stock.
            $count = $rng->getInt(1, 100) <= 10 ? 0 : $rng->getInt(3, 12);

            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'offer_id' => $offerId,
                    'code' => Crypt::encryptString(self::code($rng)),
                    'status' => 'available',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Chunks of 500 keep each INSERT well below PostgreSQL's 65,535 parameter limit.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('product_keys')->insert($chunk);
        }
    }

    private static function code(Randomizer $rng): string
    {
        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $groups[] = $rng->getBytesFromString(self::ALPHABET, 5);
        }

        return implode('-', $groups);
    }
}
