<?php

namespace App\Console\Commands;

use App\Actions\Auth\AttemptEmergencyLogin;
use App\Actions\Users\AssignInitialRoles;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates or updates the emergency local administrator and prints a new
 * strong password ONCE. The plain password is never stored or logged; running
 * the command again rotates it (and clears any lockout).
 */
class EmergencyAdminCommand extends Command
{
    protected $signature = 'gestor:emergency-admin {email : Correo de la cuenta de emergencia}';

    protected $description = 'Crea o actualiza la cuenta de emergencia (Súper Admin) y muestra una contraseña nueva una sola vez';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error('El correo no es válido.');

            return self::INVALID;
        }

        if (! Role::query()->where('name', AssignInitialRoles::SUPER_ADMIN)->where('guard_name', 'web')->exists()) {
            $this->error('No existe el rol "'.AssignInitialRoles::SUPER_ADMIN.'". Ejecuta primero los seeders de roles.');

            return self::FAILURE;
        }

        $password = Str::password(32);

        $user = DB::transaction(function () use ($email, $password): User {
            $user = User::firstOrNew(['email' => $email]);
            $created = ! $user->exists;

            $user->fill(['password' => $password]);
            $user->forceFill([
                'name' => $user->name ?: 'Administrador de emergencia',
                'is_global' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $user->assignRole(AssignInitialRoles::SUPER_ADMIN);

            activity('auth')
                ->performedOn($user)
                ->event($created ? 'emergency_admin_created' : 'emergency_admin_password_rotated')
                ->withProperties(['email' => $email, 'source' => 'console'])
                ->log($created ? 'Cuenta de emergencia creada' : 'Contraseña de emergencia rotada');

            return $user;
        });

        $this->info("Cuenta de emergencia lista: {$user->email}");
        $this->line("Contraseña: {$password}");
        $this->warn('Guárdala ahora en un lugar seguro: no se volverá a mostrar.');

        if (AttemptEmergencyLogin::configuredEmail() !== $email) {
            $this->warn('Atención: EMERGENCY_ADMIN_EMAIL no coincide con este correo; el ingreso de emergencia no lo aceptará hasta configurarlo.');
        }

        return self::SUCCESS;
    }
}
