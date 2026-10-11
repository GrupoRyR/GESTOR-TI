<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'area_id' => null,
            'area_locked' => false,
            'user_id' => null,
            'source' => Employee::SOURCE_MANUAL,
            'azure_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'job_title' => fake()->jobTitle(),
            'department' => null,
            'manager_azure_id' => null,
            'manager_id' => null,
            'status' => Employee::STATUS_ACTIVE,
            'last_synced_at' => null,
        ];
    }

    public function fromGraph(?string $azureId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => Employee::SOURCE_GRAPH,
            'azure_id' => $azureId ?? (string) Str::uuid(),
            'last_synced_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Employee::STATUS_INACTIVE,
        ]);
    }
}
