<?php

namespace Tests\Feature\Ai\Triage;

use App\Enums\Ai\EffortSize;
use App\Enums\Ai\RequestStatus;
use App\Enums\Ai\RiskLevel;
use App\Enums\Ai\Urgency;
use App\Models\AiCostRate;
use App\Models\AiRequest;
use App\Models\TelegramLink;
use App\Models\User;
use Tests\Feature\Ai\AiTestCase;

/**
 * IT triage pages (types/pages/ai-triage.ts). Page files live on the design
 * branch, so components are asserted without checking the file.
 */
abstract class TriageTestCase extends AiTestCase
{
    /** Shared props every page carries (HandleInertiaRequests). */
    protected const SHARED = ['auth', 'companies', 'current_company_id', 'flash', 'notifications_unread', 'modules', 'errors'];

    /** `TriageActionData` keys (merge_candidates only on its partial reload). */
    protected const ACTION_DATA = ['team', 'approvers'];

    /**
     * Prop keys of a page response, shared props included, sorted.
     *
     * @return list<string>
     */
    protected function propKeys(string $url, ?User $as = null): array
    {
        $keys = array_keys($this->actingAs($as ?? $this->jhon)->get($url)->assertOk()->viewData('page')['props']);
        sort($keys);

        return $keys;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    protected function withShared(array $keys): array
    {
        $all = [...self::SHARED, ...$keys];
        sort($all);

        return $all;
    }

    /**
     * An evaluated request (size M, high urgency) in "En evaluación".
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function evaluated(array $attributes = []): AiRequest
    {
        $request = $this->request([
            'status' => RequestStatus::Evaluating,
            'size' => EffortSize::M,
            'urgency' => Urgency::High,
            'risk' => RiskLevel::Low,
            'evaluated_by_id' => $this->jhon->id,
            'evaluated_at' => now()->subDay(),
            'first_responded_at' => now()->subDay(),
            'hours_per_week' => 6,
            'people_count' => 8,
            ...$attributes,
        ]);
        $request->recomputePriority();
        $request->save();

        return $request;
    }

    protected function rate(User $user, int $rate, string $from = '2026-01-01'): void
    {
        AiCostRate::create(['user_id' => $user->id, 'rate' => $rate, 'effective_from' => $from]);
    }

    protected function linkTelegram(User $user, string $chatId): void
    {
        TelegramLink::create(['user_id' => $user->id, 'chat_id' => $chatId, 'linked_at' => now()]);
    }

    /**
     * Jhon also holds the project approver role (as in production).
     */
    protected function jhonApproves(): void
    {
        $this->jhon->assignRole('Aprobador de proyectos');
    }

    /**
     * Sends a request to approval through the web endpoint.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function sendToApproval(AiRequest $request, array $overrides = [], ?User $as = null): void
    {
        $this->actingAs($as ?? $this->jhon)
            ->post(route('ai.requests.approval.store', $request), array_replace([
                'hours' => [['person_id' => $this->jhon->id, 'hours' => 28], ['person_id' => $this->superAdmin->id, 'hours' => 12]],
                'external_costs' => 0,
                'due_date' => now()->addMonth()->toDateString(),
                'approver_ids' => [$this->edward->id],
                'require_all' => false,
            ], $overrides))
            ->assertSessionHasNoErrors();
    }
}
