<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Homepage layout and banner content (D4). Order, titles and which blocks exist
 * are decided here, in the database, never in the frontend.
 */
class HomeSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $stamps = ['created_at' => $now, 'updated_at' => $now];

        DB::table('home_blocks')->insert(array_map(fn ($b) => $b + $stamps, [
            ['type' => 'banner', 'title' => null, 'endpoint' => '/home/banners', 'position' => 1, 'is_active' => true],
            ['type' => 'product_carousel', 'title' => 'Best sellers', 'endpoint' => '/home/best-sellers', 'position' => 2, 'is_active' => true],
            ['type' => 'product_carousel', 'title' => 'Deals', 'endpoint' => '/home/deals', 'position' => 3, 'is_active' => true],
        ]));

        // Links are frontend routes into the store (search lives in the store, D6),
        // in the store's own URL format (D29): platform=steam, not the API's platform[]=steam.
        DB::table('banners')->insert(array_map(fn ($b) => $b + $stamps + [
            'image_url' => null, 'is_active' => true, 'starts_at' => null, 'ends_at' => null,
        ], [
            ['title' => 'Steam keys for less', 'subtitle' => 'Compare offers from trusted sellers and save on PC games.',
                'cta_label' => 'Browse Steam', 'link_url' => '/store?platform=steam', 'position' => 1],
            ['title' => 'Play together', 'subtitle' => 'Split Fiction, It Takes Two and more co-op adventures.',
                'cta_label' => 'Find co-op games', 'link_url' => '/store?q=split+fiction', 'position' => 2],
            ['title' => 'Nintendo favourites', 'subtitle' => 'Zelda, Mario Kart and Animal Crossing keys for Switch.',
                'cta_label' => 'Shop Nintendo', 'link_url' => '/store?platform=nintendo', 'position' => 3],
        ]));
    }
}
