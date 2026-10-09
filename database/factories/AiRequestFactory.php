<?php

namespace Database\Factories;

use App\Enums\Ai\EffortSize;
use App\Enums\Ai\RequestStatus;
use App\Enums\Ai\RequestType;
use App\Enums\Ai\RiskLevel;
use App\Enums\Ai\Urgency;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRequest>
 */
class AiRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $requester = User::factory();

        return [
            'company_id' => Company::factory(),
            'area_id' => fn (array $attributes) => $attributes['company_id'] === null
                ? null
                : Area::factory()->state(['company_id' => $attributes['company_id']]),
            'requester_id' => $requester,
            'created_by_id' => fn (array $attributes) => $attributes['requester_id'],
            'type' => RequestType::New,
            'title' => fake()->sentence(6),
            'problem' => fake()->paragraph(),
            'who_suffers' => null,
            'current_process' => fake()->sentence(),
            'hours_per_week' => 3,
            'people_count' => 2,
            'expected_result' => fake()->sentence(),
            'success_criteria' => null,
            'systems' => ['Excel'],
            'urgency' => Urgency::Medium,
            'status' => RequestStatus::Received,
            'first_response_due_at' => now()->addDays(3),
        ];
    }

    public function status(RequestStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'first_responded_at' => $status === RequestStatus::Received ? null : now(),
        ]);
    }

    public function evaluated(EffortSize $size = EffortSize::M, ?User $by = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RequestStatus::Evaluating,
            'size' => $size,
            'risk' => RiskLevel::Low,
            'evaluated_by_id' => $by?->id,
            'evaluated_at' => now(),
            'first_responded_at' => now(),
        ])->afterMaking(fn (AiRequest $request) => $request->recomputePriority());
    }
}
