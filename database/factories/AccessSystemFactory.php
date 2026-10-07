<?php

namespace Database\Factories;

use App\Models\AccessSystem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessSystem>
 */
class AccessSystemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' '.fake()->unique()->numberBetween(1, 99999),
            'description' => null,
            'is_active' => true,
        ];
    }
}
