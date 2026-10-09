<?php

namespace App\Actions\Ai;

use App\Domain\Ai\CostRates;
use App\Domain\Ai\InvalidTransition;
use App\Domain\Ai\RequestStatusMachine;
use App\Domain\Ai\TriagePeople;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\ApprovalKind;
use App\Enums\Ai\RequestStatus;
use App\Models\AiEstimate;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Models\User;
use App\Notifications\AiApprovalRequested;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Mandar a aprobación" (SendToApprovalDialog, PROMPT 6.4 and 6.4.11).
 *
 * - Needs `evaluate` and an evaluated request (size set); the move to
 *   "Pendiente de aprobación" must be allowed by the status machine.
 * - Hours per person of the IT team (holders of `ai.manage`), external
 *   costs (COP) and due date become an `ai_estimates` row with its lines;
 *   each line keeps the hourly rate in force today (App\Domain\Ai\CostRates).
 *   `total_cost` = Σ hours × rate + external costs, null while somebody has
 *   no rate.
 * - One pending project approval row per selected approver (holders of
 *   `ai.projects.approve`), all with the same `requested_at` (the round),
 *   the estimate JSON snapshot, `require_all` and `is_reapproval` (a
 *   previous round was approved). Pending rows of earlier rounds are removed.
 * - Settings `ai.approval.require_other_if_evaluator`: the evaluator cannot
 *   be the only approver asked, nor be asked with `require_all`.
 * - Approvers are notified with AiApprovalRequested (mail / bell / Telegram
 *   ✅ Aprobar / ❌ Rechazar, per their preferences) after the commit; the
 *   IT timeline records the send and each notice.
 */
class SendToApproval
{
    public function __construct(
        private readonly TriagePeople $people,
        private readonly CostRates $rates,
        private readonly RequestStatusMachine $machine,
        private readonly TransitionRequest $transition,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  list<array{person_id: int, hours: int|float}>  $hours
     * @param  list<int>  $approverIds
     */
    public function handle(
        AiRequest $request,
        User $actor,
        array $hours,
        int $externalCosts,
        string $dueDate,
        array $approverIds,
        bool $requireAll,
    ): AiRequest {
        Gate::forUser($actor)->authorize('evaluate', $request);

        if ($request->size === null) {
            throw ValidationException::withMessages(['size' => 'Evalúa la solicitud (talla y urgencia) antes de mandarla a aprobación.']);
        }

        if (! $this->machine->allows($request->status, RequestStatus::PendingApproval)) {
            throw InvalidTransition::between($request->key, $request->status, RequestStatus::PendingApproval);
        }

        $lines = $this->lines($hours);
        $approvers = $this->approvers($request, $approverIds, $requireAll);

        $rows = DB::transaction(function () use ($request, $actor, $lines, $externalCosts, $dueDate, $approvers, $requireAll): Collection {
            $estimate = $this->estimate($request, $actor, $lines, $externalCosts, $dueDate);
            $snapshot = $estimate->toSnapshot();
            $requestedAt = Carbon::now();

            $isReapproval = $request->approvals()
                ->where('kind', ApprovalKind::Project->value)
                ->where('decision', ApprovalDecision::Approved->value)
                ->exists();

            $request->approvals()->where('kind', ApprovalKind::Project->value)->pending()->delete();

            $rows = $approvers->map(fn (User $approver) => $request->approvals()->create([
                'kind' => ApprovalKind::Project,
                'user_id' => $approver->id,
                'estimate' => $snapshot,
                'require_all' => $requireAll,
                'is_reapproval' => $isReapproval,
                'requested_by_id' => $actor->id,
                'requested_at' => $requestedAt,
            ])->setRelation('approver', $approver));

            $this->transition->apply($request, RequestStatus::PendingApproval, $actor);

            $request->recordEvent(
                'approval',
                'amber',
                "{$actor->name} la mandó a aprobación",
                $actor,
                null,
                'aprobadores: '.self::join($approvers->pluck('name')->all()).($requireAll && $approvers->count() > 1 ? ' · esperan a todos' : ''),
            );

            return $rows;
        });

        $this->notify($request, $rows);

        return $request;
    }

    /**
     * Notifies each asked approver and records the notice on the timeline.
     *
     * @param  Collection<int, AiRequestApproval>  $rows
     */
    public function notify(AiRequest $request, Collection $rows, bool $reminder = false): void
    {
        foreach ($rows as $row) {
            $approver = $row->approver;

            if ($approver === null) {
                continue;
            }

            $notification = AiApprovalRequested::forApproval($request, $row);
            $channels = $notification->via($approver);
            $approver->notify($notification);

            if ($channels !== []) {
                $request->recordEvent(
                    'notification',
                    'amber',
                    ($reminder ? 'Recordatorio enviado a ' : 'Aviso enviado a ').$approver->name,
                    null,
                    null,
                    self::channelLabels($channels),
                );
            }
        }
    }

    /**
     * "Telegram, correo y campana".
     *
     * @param  list<string>  $channels
     */
    public static function channelLabels(array $channels): string
    {
        return self::join(array_map(fn (string $channel) => match ($channel) {
            'telegram' => 'Telegram',
            'mail' => 'correo',
            'database' => 'campana',
            default => $channel,
        }, $channels));
    }

    /**
     * "A, B y C".
     *
     * @param  list<string>  $items
     */
    public static function join(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' y '.$last;
    }

    /**
     * Hours per team member (rows of the same person are added up).
     *
     * @param  list<array{person_id: int, hours: int|float}>  $hours
     * @return array<int, float> user id => hours
     */
    private function lines(array $hours): array
    {
        $team = array_flip($this->people->team()->modelKeys());
        $lines = [];

        foreach ($hours as $row) {
            $personId = (int) ($row['person_id'] ?? 0);
            $value = (float) ($row['hours'] ?? 0);

            if (! isset($team[$personId])) {
                throw ValidationException::withMessages(['hours' => 'Solo las personas de TI van en el estimado.']);
            }

            if ($value > 0) {
                $lines[$personId] = ($lines[$personId] ?? 0) + $value;
            }
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['hours' => 'Escribe las horas de al menos una persona.']);
        }

        return $lines;
    }

    /**
     * @param  list<int>  $approverIds
     * @return Collection<int, User>
     */
    private function approvers(AiRequest $request, array $approverIds, bool $requireAll): Collection
    {
        $holders = $this->people->approvers()->keyBy('id');
        $ids = array_values(array_unique(array_map('intval', $approverIds)));

        if ($ids === [] || array_diff($ids, $holders->keys()->all()) !== []) {
            throw ValidationException::withMessages(['approver_ids' => 'Elige aprobadores de proyectos (rol Aprobador de proyectos).']);
        }

        $evaluator = (int) $request->evaluated_by_id;

        if ($evaluator !== 0 && in_array($evaluator, $ids, true) && (bool) $this->settings->get('ai.approval.require_other_if_evaluator', false)) {
            if (count($ids) === 1) {
                throw ValidationException::withMessages(['approver_ids' => 'Quien evaluó la solicitud no puede aprobarla: elige también a otro aprobador.']);
            }

            if ($requireAll) {
                throw ValidationException::withMessages(['approver_ids' => 'Quien evaluó no puede aprobar: quítalo de la lista o no esperes a todos.']);
            }
        }

        return collect($ids)->map(fn (int $id) => $holders[$id])->values();
    }

    /**
     * @param  array<int, float>  $lines
     */
    private function estimate(AiRequest $request, User $actor, array $lines, int $externalCosts, string $dueDate): AiEstimate
    {
        $rates = $this->rates->ratesFor(array_keys($lines));
        $known = count(array_intersect_key($lines, $rates)) === count($lines);
        $team = 0.0;

        foreach ($lines as $userId => $hours) {
            $team += $hours * ($rates[$userId] ?? 0);
        }

        $estimate = $request->estimates()->create([
            'hours_total' => array_sum($lines),
            'external_costs' => $externalCosts,
            'total_cost' => $known ? (int) round($team + $externalCosts) : null,
            'due_date' => $dueDate,
            'created_by_id' => $actor->id,
        ]);

        foreach ($lines as $userId => $hours) {
            $estimate->lines()->create(['user_id' => $userId, 'hours' => $hours, 'hourly_rate' => $rates[$userId] ?? null]);
        }

        return $estimate;
    }
}
