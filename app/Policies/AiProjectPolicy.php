<?php

namespace App\Policies;

use App\Models\AiProject;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;

/**
 * Who may do what with a project (PROMPT 6.4.3, 6.4.9):
 *
 * - viewAny (the list): `ai.manage`.
 * - view (summary, board, task page): `ai.manage` with access to the
 *   company, the team (lead and members), people assigned to one of its
 *   tasks, and `ai.area.view` holders whose employee area is the project's
 *   area (read only). Money still needs `ai.costs.view` (`viewCosts`).
 * - manage (tasks, requirements, report, any change): `ai.manage` with
 *   access to the company.
 */
class AiProjectPolicy
{
    public function __construct(private readonly CurrentCompany $companies) {}

    public function viewAny(User $user): bool
    {
        return $user->can('ai.manage');
    }

    public function view(User $user, AiProject $project): bool
    {
        if ($this->manage($user, $project)) {
            return true;
        }

        if ((int) $project->lead_id === $user->id || $project->members()->where('user_id', $user->id)->exists()) {
            return true;
        }

        if ($project->tasks()->whereHas('assignees', fn ($query) => $query->whereKey($user->id))->exists()) {
            return true;
        }

        $employee = $user->employee;

        return $user->can('ai.area.view')
            && $project->area_id !== null
            && $employee !== null
            && (int) $employee->company_id === (int) $project->company_id
            && (int) $employee->area_id === (int) $project->area_id;
    }

    public function manage(User $user, AiProject $project): bool
    {
        return $user->can('ai.manage') && $this->companies->canAccess($user, (int) $project->company_id);
    }

    public function viewCosts(User $user): bool
    {
        return $user->can('ai.costs.view');
    }
}
