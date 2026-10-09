<?php

namespace Database\Factories;

use App\Enums\Ai\ProductStatus;
use App\Models\AiProduct;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Companies and areas are pivots: attach them in the test
 * (`$product->companies()->attach(...)`).
 *
 * @extends Factory<AiProduct>
 */
class AiProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));

        return [
            'slug' => Str::slug($name),
            'name' => $name,
            'description' => fake()->sentence(),
            'stack' => fake()->randomElement(['Laravel', 'PHP', 'Next.js', 'Python']),
            'hosting' => fake()->randomElement(['GoDaddy', 'Vercel', 'Equipo local']),
            'status' => ProductStatus::Production,
            'current_version' => 'v1.0.0',
        ];
    }

    public function status(ProductStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
