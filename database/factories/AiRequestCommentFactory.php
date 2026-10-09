<?php

namespace Database\Factories;

use App\Enums\Ai\CommentVisibility;
use App\Models\AiRequest;
use App\Models\AiRequestComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRequestComment>
 */
class AiRequestCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_request_id' => AiRequest::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
            'visibility' => CommentVisibility::Public,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (array $attributes) => ['visibility' => CommentVisibility::Internal]);
    }
}
