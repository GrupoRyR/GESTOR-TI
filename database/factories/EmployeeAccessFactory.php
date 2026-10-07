<?php

namespace Database\Factories;

use App\Models\AccessSystem;
use App\Models\Employee;
use App\Models\EmployeeAccess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAccess>
 */
class EmployeeAccessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'access_system_id' => AccessSystem::factory(),
            'username' => fake()->unique()->userName(),
            'password' => fake()->password(12, 20),
            'notes' => null,
        ];
    }
}
