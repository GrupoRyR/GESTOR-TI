<?php

namespace Database\Factories;

use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProject>
 */
class AiProjectFactory extends Factory
{
    protected $model = AiProject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => ucfirst($this->faker->words(3, true)),
            'objective' => $this->faker->sentence(),
            'status' => RequestStatus::Planned,
            'start_date' => now()->toDateString(),
        ];
    }
}
