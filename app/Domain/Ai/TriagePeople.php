<?php

namespace App\Domain\Ai;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who works the triage: the IT team (holders of `ai.manage`, through a role
 * or directly) and the project approvers (holders of `ai.projects.approve`,
 * only the "Aprobador de proyectos" role). Active users only, by name.
 */
final class TriagePeople
{
    /**
     * @return Collection<int, User>
     */
    public function team(): Collection
    {
        return $this->holders('ai.manage');
    }

    /**
     * @return Collection<int, User>
     */
    public function approvers(): Collection
    {
        return $this->holders('ai.projects.approve');
    }

    /**
     * @return Collection<int, User>
     */
    private function holders(string $permission): Collection
    {
        return User::permission($permission)
            ->where('active', true)
            ->with('employee')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
