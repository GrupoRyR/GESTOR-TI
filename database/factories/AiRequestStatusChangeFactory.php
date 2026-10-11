<?php

namespace Database\Factories;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\AiRequestStatusChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRequestStatusChange>
 */
class AiRequestStatusChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_request_id' => AiRequest::factory(),
            'from_status' => null,
            'to_status' => RequestStatus::Received,
            'user_id' => null,
            'note' => null,
        ];
    }
}
