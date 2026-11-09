<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for local development and production.
 *
 * In production this runs on EVERY container start: the serversideup image runs
 * `migrate --force --seed` (AUTORUN_LARAVEL_MIGRATION_SEED in the Dockerfile).
 * That is why it must be:
 * - idempotent: if products already exist it does nothing. It skips instead of
 *   throwing, because an error at startup would stop the container (D19).
 * - atomic: everything runs in one transaction, so a failure halfway leaves an
 *   empty catalog and the next start simply tries again.
 * Seeding in the container also means keys are encrypted with production's APP_KEY.
 *
 * Locally: `php artisan migrate:fresh --seed` rebuilds everything from scratch.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (Product::query()->exists()) {
            $this->command?->info('Products already exist, skipping demo seed.');

            return;
        }

        DB::transaction(function () {
            $this->call([
                CatalogSeeder::class,      // platforms, regions, sellers, products, offers
                ProductKeySeeder::class,   // encrypted keys per offer
                OrderHistorySeeder::class, // users + past orders (feeds Best sellers)
                HomeSeeder::class,         // homepage blocks and banners
            ]);
        });
    }
}
