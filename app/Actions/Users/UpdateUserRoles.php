<?php

namespace App\Actions\Users;

use App\Models\Area;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Administración › Usuarios › Guardar roles. Syncs the user's roles (direct
 * permissions such as `ai.vault.access` are untouched) and keeps the area
 * approver in line with the `requires_area` role ("Aprobador de área"):
 *
 * - role included with `$approverAreaId`: the user becomes that area's
 *   approver (`areas.approver_user_id`, replacing whoever it was) and stops
 *   approving any other area;
 * - role removed (held before, not now): every area the user approved is
 *   cleared.
 *
 * Area changes go through the model, so the `areas` activity log records
 * them too; the role change is logged once (`users` / `user_roles_updated`).
 */
class UpdateUserRoles
{
    /**
     * @param  list<string>  $roles
     */
    public function handle(User $actor, User $user, array $roles, ?int $approverAreaId): void
    {
        DB::transaction(function () use ($actor, $user, $roles, $approverAreaId) {
            $beforeRoles = $user->getRoleNames()->sort()->values()->all();
            $beforeArea = $this->approvedAreaName($user);
            $hadAreaRole = $user->roles()->where('requires_area', true)->exists();

            $user->syncRoles($roles);

            if ($approverAreaId !== null) {
                $this->approvedAreas($user)->where('id', '!=', $approverAreaId)->get()
                    ->each(fn (Area $area) => $area->update(['approver_user_id' => null]));

                $area = Area::withoutGlobalScopes()->findOrFail($approverAreaId);

                if ($area->approver_user_id !== $user->id) {
                    $area->update(['approver_user_id' => $user->id]);
                }
            } elseif ($hadAreaRole) {
                $this->approvedAreas($user)->get()
                    ->each(fn (Area $area) => $area->update(['approver_user_id' => null]));
            }

            $afterRoles = $user->fresh()->getRoleNames()->sort()->values()->all();
            $afterArea = $this->approvedAreaName($user);

            if ($beforeRoles === $afterRoles && $beforeArea === $afterArea) {
                return;
            }

            $old = ['roles' => $beforeRoles];
            $new = ['roles' => $afterRoles];

            if ($beforeArea !== $afterArea) {
                $old['approver_area'] = $beforeArea;
                $new['approver_area'] = $afterArea;
            }

            activity('users')
                ->causedBy($actor)
                ->performedOn($user)
                ->event('user_roles_updated')
                ->withProperties(['old' => $old, 'attributes' => $new])
                ->log('Roles del usuario actualizados');
        });
    }

    /**
     * @return Builder<Area>
     */
    private function approvedAreas(User $user): Builder
    {
        return Area::withoutGlobalScopes()->where('approver_user_id', $user->id);
    }

    private function approvedAreaName(User $user): ?string
    {
        return $this->approvedAreas($user)->orderBy('id')->value('name');
    }
}
