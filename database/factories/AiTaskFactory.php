<?php

namespace Database\Factories;

use App\Models\AiTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Callers pass `ai_project_id` and `ai_project_column_id`; the key and
 * the position (end of the column) are filled by the model.
 *
 * @extends Factory<AiTask>
 */
class AiTaskFactory extends Factory
{
    protected $model = AiTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => ucfirst($this->faker->words(3, true)),
            'description' => null,
            'labels' => [],
        ];
    }
}
