<?php

namespace Database\Factories;

use App\Models\Platform;
use App\Models\Product;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 1_000_000),
            'name' => $name,
            'description' => fake()->sentence(),
            'image_url' => null,
            'platform_id' => Platform::factory(),
            'region_id' => Region::factory(),
            'release_date' => fake()->date(),
        ];
    }
}
