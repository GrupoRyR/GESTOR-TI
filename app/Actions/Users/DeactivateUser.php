<?php

namespace App\Actions\Users;

use App\Models\Area;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Administración › Usuarios › Desactivar. The user can no longer sign in
 * (Microsoft or emergency login), their open sessions end on the next
 * request (EnsureUserIsActive; database sessions are also deleted now) and
 * they get no mail or Telegram notices; they stop approving areas. History, requests and roles are
 * kept. Idempotent: an inactive user is left as is (no new log entry).
 */
class DeactivateUser
{
    public function handle(User $actor, User $user): void
    {
        if (! $user->isActive()) {
            return;
        }

        DB::transaction(function () use ($actor, $user) {
            $user->forceFill(['active' => false])->save();

            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            // A deactivated user no longer approves areas (each area change
            // is also logged by the Area model).
            $areas = Area::query()->withoutGlobalScopes()->where('approver_user_id', $user->id)->orderBy('name')->get();
            $areas->each(fn (Area $area) => $area->forceFill(['approver_user_id' => null])->save());

            $old = ['active' => true];
            $new = ['active' => false];

            if ($areas->isNotEmpty()) {
                $old['approver_areas'] = $areas->pluck('name')->values()->all();
                $new['approver_areas'] = [];
            }

            activity('users')
                ->causedBy($actor)
                ->performedOn($user)
                ->event('user_deactivated')
                ->withProperties(['old' => $old, 'attributes' => $new])
                ->log('Usuario desactivado');
        });
    }
}
