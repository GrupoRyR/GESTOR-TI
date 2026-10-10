<?php

namespace App\Domain\Ai;

use App\Models\AiProject;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who can be assigned to the tasks of a project (board "Responsable"
 * filter and the drawer's picker): the project team plus the IT team
 * (`ai.manage` holders), active users only, by name.
 */
final class BoardPeople
{
    public function __construct(private readonly TriagePeople $triage) {}

    /**
     * @return Collection<int, User>
     */
    public function for(AiProject $project): Collection
    {
        $team = $project->members()->with('user.employee')->get()->map->user;

        return $this->triage->team()
            ->toBase()
            ->merge($team)
            ->push($project->lead)
            ->filter(fn ($user) => $user instanceof User && $user->isActive())
            ->unique('id')
            ->sortBy(fn (User $user) => [mb_strtolower($user->name), $user->id])
            ->values();
    }

    /**
     * @return list<int>
     */
    public function ids(AiProject $project): array
    {
        return $this->for($project)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
