<?php

namespace Database\Factories;

use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Seller> */
class SellerFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'rating' => fake()->randomFloat(2, 3, 5)];
    }
}
