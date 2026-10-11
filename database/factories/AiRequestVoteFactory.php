<?php

namespace Database\Factories;

use App\Models\AiRequest;
use App\Models\AiRequestVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRequestVote>
 */
class AiRequestVoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_request_id' => AiRequest::factory(),
            'user_id' => User::factory(),
        ];
    }
}
