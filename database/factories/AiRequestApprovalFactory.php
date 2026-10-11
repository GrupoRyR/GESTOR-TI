<?php

namespace Database\Factories;

use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\ApprovalKind;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pending area approval by default; `project()` adds an estimate snapshot.
 *
 * @extends Factory<AiRequestApproval>
 */
class AiRequestApprovalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_request_id' => AiRequest::factory(),
            'kind' => ApprovalKind::Area,
            'user_id' => User::factory(),
            'decision' => ApprovalDecision::Pending,
            'requested_at' => now(),
        ];
    }

    public function project(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ApprovalKind::Project,
            'estimate' => [
                'hours_by_person' => [],
                'hours_total' => 40,
                'external_costs' => 0,
                'due_date' => '2026-10-31',
                'total_cost' => 6_400_000,
            ],
        ]);
    }
}
