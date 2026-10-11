<?php

namespace Database\Factories;

use App\Models\AcceptanceCriterion;
use App\Models\AiRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcceptanceCriterion>
 */
class AcceptanceCriterionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_request_id' => AiRequest::factory(),
            'position' => 1,
            'text' => fake()->sentence(),
        ];
    }
}
