<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shortName = fake()->unique()->company();
        $slug = Str::slug($shortName).'-'.fake()->unique()->numberBetween(1, 99999);
        $domain = $slug.'.test';

        return [
            'name' => $shortName.' S.A.S.',
            'short_name' => Str::limit($shortName, 55, ''),
            'slug' => Str::limit($slug, 60, ''),
            'tenant_id' => (string) Str::uuid(),
            'domains' => [$domain],
            'logo_path' => null,
            'logo_dark_path' => null,
            'colors' => [
                'primary' => fake()->hexColor(),
                'accent' => fake()->hexColor(),
                'dark' => fake()->hexColor(),
            ],
            'sender_email' => 'info@'.$domain,
            'sender_name' => $shortName,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
