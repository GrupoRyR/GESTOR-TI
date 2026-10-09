<?php

namespace App\Actions\Ai;

use App\Enums\Ai\RejectReason;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Unir con otra" (MergeDialog, duplicate banner): `$duplicate` joins
 * `$into`, the request that stays.
 *
 * - The requester and the +1 voters of the duplicate become +1 voters and
 *   followers of the one that stays (its requester excluded), so the
 *   impact and the suggested priority grow.
 * - The duplicate is rejected with reason "Está duplicada", a public note
 *   naming the one that stays, and `merged_into_id`.
 * - Both get a `merge` event on the IT timeline.
 *
 * Same company only; neither may be rejected or closed.
 */
class MergeRequests
{
    public function __construct(private readonly TransitionRequest $transition) {}

    public function handle(AiRequest $duplicate, AiRequest $into, User $actor): AiRequest
    {
        Gate::forUser($actor)->authorize('evaluate', $duplicate);
        Gate::forUser($actor)->authorize('evaluate', $into);

        if ($duplicate->is($into)) {
            throw ValidationException::withMessages(['into' => 'Elige otra solicitud: no se puede unir consigo misma.']);
        }

        if ((int) $duplicate->company_id !== (int) $into->company_id) {
            throw ValidationException::withMessages(['into' => 'Solo se unen solicitudes de la misma empresa.']);
        }

        if ($duplicate->status->isFinished() || $into->status->isFinished()) {
            throw ValidationException::withMessages(['into' => 'Las solicitudes rechazadas o cerradas no se unen.']);
        }

        return DB::transaction(function () use ($duplicate, $into, $actor): AiRequest {
            $people = $duplicate->votes()->pluck('user_id')
                ->push($duplicate->requester_id)
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => $id === (int) $into->requester_id)
                ->unique()
                ->values();

            foreach ($people as $userId) {
                $into->votes()->firstOrCreate(['user_id' => $userId]);
            }

            $into->followers()->syncWithoutDetaching([...$people->all(), ...$duplicate->followers()->pluck('users.id')->all()]);
            $into->votes_count = $into->votes()->count();
            $into->recomputePriority();
            $into->save();

            $duplicate->merged_into_id = $into->id;
            $duplicate->save();

            $this->transition->apply(
                $duplicate,
                RequestStatus::Rejected,
                $actor,
                "Se unió con {$into->key} («{$into->title}»): ahí sigue tu pedido y cuenta tu +1.",
                RejectReason::Duplicate,
            );

            $duplicate->recordEvent('merge', 'slate', "Unida con {$into->key}", $actor);
            $count = $people->count();
            $into->recordEvent('merge', 'slate', "{$duplicate->key} se unió aquí", $actor, null, $count === 1 ? '1 persona sumada' : "{$count} personas sumadas");

            activity('ai')
                ->performedOn($duplicate)
                ->causedBy($actor)
                ->event('ai_request_merged')
                ->withProperties(['key' => $duplicate->key, 'into' => $into->key])
                ->log("{$duplicate->key} se unió con {$into->key}");

            return $into;
        });
    }
}
