<?php

namespace Database\Factories;

use App\Models\AiRequest;
use App\Models\AiSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiSuggestion>
 */
class AiSuggestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => AiRequest::class,
            'subject_id' => AiRequest::factory(),
            'kind' => 'summary',
            'state' => AiSuggestion::READY,
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'payload' => ['text' => fake()->sentence()],
            'input_hashes' => null,
        ];
    }
}
