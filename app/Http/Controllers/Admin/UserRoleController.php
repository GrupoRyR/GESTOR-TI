<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRolesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Administración › Usuarios: roles of a user. Direct permissions (such as
 * `ai.vault.access`) are untouched.
 */
class UserRoleController extends Controller
{
    public function update(UpdateUserRolesRequest $request, User $user): RedirectResponse
    {
        $before = $user->getRoleNames()->sort()->values()->all();
        $after = collect($request->roleNames())->sort()->values()->all();

        DB::transaction(function () use ($request, $user, $before, $after) {
            $user->syncRoles($after);

            activity()
                ->causedBy($request->user())
                ->performedOn($user)
                ->event('user_roles_updated')
                ->withProperties([
                    'old' => ['roles' => $before],
                    'attributes' => ['roles' => $after],
                ])
                ->log('Roles del usuario actualizados');
        });

        return back()->with('success', 'Roles del usuario actualizados.');
    }
}
