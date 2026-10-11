<?php

namespace App\Actions\Ai;

use App\Domain\Ai\InvalidTransition;
use App\Models\AiRequirement;
use App\Models\AiTask;
use App\Models\User;
use App\Notifications\AiRequirementAnswered;
use App\Notifications\AiTaskUnblocked;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * "Enviar respuesta" (`ai.requirements.answer`): the recipient (or IT on
 * their behalf) answers, optionally with one file on the private disk
 * (`ai/projects/{project}/requirements`). The requirement leaves the open
 * list, so the task it blocked is free again: its assignees are notified
 * (AiTaskUnblocked) unless another open requirement still blocks it. The
 * IT person who asked is told too (AiRequirementAnswered).
 */
class AnswerRequirement
{
    public function handle(AiRequirement $requirement, User $actor, string $answer, ?UploadedFile $file): void
    {
        if (! $requirement->isOpen()) {
            throw new InvalidTransition('Este requerimiento ya fue respondido.');
        }

        $project = $requirement->project;

        DB::transaction(function () use ($requirement, $actor, $answer, $file, $project): void {
            $attributes = [
                'status' => AiRequirement::ANSWERED,
                'answer' => trim($answer),
                'answered_at' => now(),
                'answered_by_id' => $actor->id,
            ];

            if ($file !== null) {
                $disk = (string) config('gestor.ai.attachments.disk', 'local');
                $attributes += [
                    'answer_disk' => $disk,
                    'answer_path' => $file->store('ai/projects/'.$requirement->ai_project_id.'/requirements', $disk),
                    'answer_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'answer_size' => (int) $file->getSize(),
                ];
            }

            $requirement->forceFill($attributes)->save();
            $project?->recordActivity('requirement', "respondió el requerimiento {$requirement->title}", $actor);
        });

        $task = $requirement->blocksTask;

        if ($project !== null && $task instanceof AiTask && ! $task->blockingRequirements()->exists()) {
            $task->assignees()->get()
                ->reject(fn (User $user) => $user->id === $actor->id)
                ->each(fn (User $user) => $user->notify(AiTaskUnblocked::for($project, $task, $requirement, $actor->name)));
        }

        $requester = $requirement->requestedBy;

        if ($requester !== null && $requester->id !== $actor->id) {
            $requester->notify(AiRequirementAnswered::for($requirement, $actor->name));
        }
    }
}
