<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\DepartmentAreaMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentAreaMapping>
 */
class DepartmentAreaMappingFactory extends Factory
{
    /**
     * The company is taken from the area (see `forArea()`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department' => fake()->unique()->word(),
        ];
    }

    public function forArea(Area $area): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $area->company_id,
            'area_id' => $area->id,
        ]);
    }
}
