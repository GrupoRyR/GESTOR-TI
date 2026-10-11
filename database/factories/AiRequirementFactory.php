<?php

namespace Database\Factories;

use App\Models\AiRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Callers pass the project (`ai_project_id`, `company_id`) and usually the
 * recipient.
 *
 * @extends Factory<AiRequirement>
 */
class AiRequirementFactory extends Factory
{
    protected $model = AiRequirement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => ucfirst($this->faker->words(4, true)),
            'detail' => null,
            'recipient_label' => '[RESPONSABLE]',
            'area_name' => 'Área',
            'due_date' => now()->addDays(3)->toDateString(),
            'status' => AiRequirement::PENDING,
        ];
    }
}
