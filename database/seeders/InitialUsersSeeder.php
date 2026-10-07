<?php

namespace Database\Seeders;

use App\Actions\Users\AssignInitialRoles;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Applies the initial roles of config/gestor.php (`initial_users`) to users
 * that already exist. Users are created on their first Microsoft login (A5),
 * which calls the same action. Safe to run repeatedly.
 */
class InitialUsersSeeder extends Seeder
{
    public function run(AssignInitialRoles $assignInitialRoles): void
    {
        User::query()->orderBy('id')->each(fn (User $user) => $assignInitialRoles->handle($user));
    }
}
