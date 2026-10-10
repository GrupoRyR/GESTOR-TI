<?php

namespace App\Actions\Ai;

use App\Models\AiProject;
use App\Models\AiRequirement;
use App\Models\AiTask;
use App\Models\User;
use App\Notifications\AiRequirementRequested;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * "Enviar requerimiento" (`ai.requirements.store`, RequirementStorePayload):
 *
 * - "Qué necesitas" becomes the title (first line, 160 characters) and
 *   the detail (the following lines, or the whole text when the title had
 *   to be cut).
 * - Recipient: a user, whose area comes from their employee record; null
 *   while IT does not know who answers ("[RESPONSABLE {ÁREA DEL PROYECTO}]").
 * - `blocks_task`: the task waits on it (blocked) until it is answered.
 * - The recipient is notified (AiRequirementRequested); activity feed.
 */
class CreateRequirement
{
    private const TITLE_MAX = 160;

    public function handle(AiProject $project, User $actor, string $what, ?User $recipient, CarbonImmutable $dueDate, ?AiTask $blocks): AiRequirement
    {
        [$title, $detail] = self::split($what);
        $employee = $recipient?->employee;
        $area = $employee?->area_id !== null ? $employee->area()->withoutGlobalScopes()->first() : null;
        $area ??= $recipient === null ? $project->area : null;

        $requirement = AiRequirement::create([
            'company_id' => $project->company_id,
            'ai_project_id' => $project->id,
            'title' => $title,
            'detail' => $detail,
            'recipient_id' => $recipient?->id,
            'recipient_label' => $recipient?->name ?? '[RESPONSABLE '.Str::upper((string) ($area?->name ?? 'del área')).']',
            'area_id' => $area?->id,
            'area_name' => (string) ($area?->name ?? 'Sin área'),
            'due_date' => $dueDate->toDateString(),
            'blocks_task_id' => $blocks?->id,
            'requested_by_id' => $actor->id,
        ]);

        $project->recordActivity('requirement', "pidió «{$title}» a {$requirement->recipient_label}", $actor);

        $recipient?->notify(AiRequirementRequested::for($requirement));

        return $requirement;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    public static function split(string $what): array
    {
        $text = trim(str_replace("\r\n", "\n", $what));
        $lines = preg_split('/\n+/', $text) ?: [$text];
        $first = trim((string) array_shift($lines));
        $rest = trim(implode("\n", $lines));

        if (mb_strlen($first) > self::TITLE_MAX) {
            return [Str::limit($first, self::TITLE_MAX - 1, '…'), $text];
        }

        return [$first, $rest === '' ? null : $rest];
    }
}
