<?php

namespace App\Actions\Ai;

use App\Domain\Ai\InvalidTransition;
use App\Models\AiRequirement;
use App\Models\User;
use App\Notifications\AiRequirementRequested;
use Illuminate\Validation\ValidationException;

/**
 * "No me corresponde" (`ai.requirements.reassign`): the recipient passes
 * the requirement to a coworker (with an optional note for them). The new
 * person becomes the recipient (label and area from their employee
 * record), is notified, and the activity feed tells IT.
 */
class ReassignRequirement
{
    public function handle(AiRequirement $requirement, User $actor, User $to, ?string $note): void
    {
        if (! $requirement->isOpen()) {
            throw new InvalidTransition('Este requerimiento ya fue respondido.');
        }

        if ((int) $requirement->recipient_id === $to->id) {
            throw ValidationException::withMessages(['user_id' => 'Esa persona ya lo tiene.']);
        }

        $employee = $to->employee;
        $area = $employee?->area_id !== null ? $employee->area()->withoutGlobalScopes()->first() : null;
        $note = $note === null || trim($note) === '' ? null : trim($note);

        $requirement->forceFill([
            'recipient_id' => $to->id,
            'recipient_label' => $to->name,
            'area_id' => $area?->id ?? $requirement->area_id,
            'area_name' => $area?->name ?? $requirement->area_name,
            'detail' => $note === null ? $requirement->detail : trim(($requirement->detail ?? '')."\n\nNota de {$actor->name}: {$note}"),
        ])->save();

        $requirement->project?->recordActivity('requirement', "pasó el requerimiento {$requirement->title} a {$to->name}", $actor);

        $to->notify(AiRequirementRequested::for($requirement));
    }
}
