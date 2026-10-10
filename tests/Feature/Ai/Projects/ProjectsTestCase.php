<?php

namespace Tests\Feature\Ai\Projects;

use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use App\Models\AiRequirement;
use App\Models\AiTask;
use App\Models\AiTimeEntry;
use App\Models\Role;
use App\Models\User;
use Tests\Feature\Ai\Triage\TriageTestCase;

/**
 * Project pages (types/pages/ai-projects.ts): a project of Segurex ·
 * Ventas led by Jhon, built through the factories.
 */
abstract class ProjectsTestCase extends TriageTestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function project(array $attributes = []): AiProject
    {
        return AiProject::factory()->create(array_replace([
            'company_id' => $this->segurex->id,
            'area_id' => $this->ventas->id,
            'lead_id' => $this->jhon->id,
            'status' => RequestStatus::Planned,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<User>  $assignees
     */
    protected function task(AiProject $project, string $column = 'todo', array $attributes = [], array $assignees = []): AiTask
    {
        $task = AiTask::factory()->create([
            'ai_project_id' => $project->id,
            'ai_project_column_id' => $project->column($column)->id,
            ...$attributes,
        ]);

        if ($assignees !== []) {
            $task->assignees()->sync(collect($assignees)->pluck('id')->all());
        }

        return $task;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function requirement(AiProject $project, array $attributes = []): AiRequirement
    {
        return AiRequirement::factory()->create(array_replace([
            'company_id' => $project->company_id,
            'ai_project_id' => $project->id,
            'recipient_id' => $this->paola->id,
            'recipient_label' => $this->paola->name,
            'area_id' => $this->ventas->id,
            'area_name' => 'Ventas',
            'requested_by_id' => $this->jhon->id,
            'due_date' => now()->addDays(3)->toDateString(),
        ], $attributes));
    }

    protected function hours(AiProject $project, User $user, float $hours, ?AiTask $task = null, ?string $date = null): AiTimeEntry
    {
        return AiTimeEntry::create([
            'user_id' => $user->id,
            'ai_project_id' => $project->id,
            'ai_task_id' => $task?->id,
            'date' => $date ?? now()->toDateString(),
            'hours' => $hours,
            'origin' => 'manual',
            'note' => 'Trabajo',
        ]);
    }

    /**
     * IT member who manages the Gestor IA but cannot see costs.
     */
    protected function itWithoutCosts(): User
    {
        $role = Role::create(['name' => 'TI sin costos', 'guard_name' => 'web']);
        $role->givePermissionTo(['ai.manage', 'ai.requests.create', 'companies.view_all']);

        return $this->user($this->segurex, 'TI sin costos', 'Andrea TI');
    }
}
