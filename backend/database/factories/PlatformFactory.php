<?php

namespace Database\Factories;

use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Platform> */
class PlatformFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->unique()->numberBetween(1, 1_000_000);

        return ['name' => $name, 'slug' => Str::slug($name)];
    }
}
