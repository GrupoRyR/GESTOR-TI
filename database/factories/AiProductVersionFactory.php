<?php

namespace Database\Factories;

use App\Enums\Ai\ProductVersionSource;
use App\Models\AiProduct;
use App\Models\AiProductVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProductVersion>
 */
class AiProductVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_product_id' => AiProduct::factory(),
            'version' => 'v'.fake()->unique()->numerify('#.#.#'),
            'released_at' => fake()->dateTimeBetween('-1 year')->format('Y-m-d'),
            'summary' => fake()->sentence(),
            'notes' => null,
            'source' => ProductVersionSource::Manual,
        ];
    }
}
