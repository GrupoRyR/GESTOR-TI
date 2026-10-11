<?php

namespace App\Actions\Auth;

use App\Models\Alert;
use App\Models\User;
use App\Services\Alerts\AlertService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Emergency local login: only the account configured in
 * `services.emergency.admin_email` may sign in with a password.
 *
 * Two independent lockouts, both after 5 failures for 15 minutes:
 * - RateLimiter keyed by username (email) + IP (applies to ANY email, so its message
 *   reveals nothing about which account exists);
 * - persistent counter on the user (`failed_login_attempts`, `locked_until`),
 *   which survives cache clears and IP changes. While it is active the user
 *   gets the generic error (not a lockout message), so a third party cannot
 *   tell the emergency account apart.
 *
 * Every attempt is written to the activity log (`auth` log name); the
 * password is never logged. Locking the emergency account (either lockout
 * reached by an attempt) raises a critical `auth` alert grouped under
 * `auth.emergency_lockout`.
 */
class AttemptEmergencyLogin
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    public const GENERIC_ERROR = 'Las credenciales no son válidas.';

    public const INACTIVE_ERROR = 'La cuenta de emergencia está desactivada.';

    /** Form field that carries the credential errors (`Auth/Emergency`). */
    public const ERROR_FIELD = 'password';

    /** Alert group of every emergency-account lockout (`AlertService`). */
    public const LOCKOUT_GROUP_KEY = 'auth.emergency_lockout';

    public function __construct(private readonly AlertService $alerts) {}

    /**
     * What `Auth/Emergency` may show after a failed attempt. It comes ONLY
     * from the per-username+IP RateLimiter, which counts every username the
     * same way, so it never tells whether the typed username is the
     * emergency account (the persistent per-user lock stays silent).
     *
     * @return array{lockout: array{locked_until: string}|null, attempts_left: int}
     */
    public static function throttleState(string $username, string $ip): array
    {
        $key = self::throttleKey($username, $ip);
        $locked = RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS);

        return [
            'lockout' => $locked
                ? ['locked_until' => now()->addSeconds(RateLimiter::availableIn($key))->toIso8601String()]
                : null,
            'attempts_left' => max(0, self::MAX_ATTEMPTS - RateLimiter::attempts($key)),
        ];
    }

    public static function throttleKey(string $username, string $ip): string
    {
        return 'emergency-login:'.sha1(strtolower(trim($username)).'|'.$ip);
    }

    public static function enabled(): bool
    {
        return self::configuredEmail() !== null;
    }

    public static function configuredEmail(): ?string
    {
        $email = strtolower(trim((string) config('services.emergency.admin_email')));

        return $email === '' ? null : $email;
    }

    /**
     * @throws ValidationException
     */
    public function handle(string $email, string $password, string $ip): User
    {
        $email = strtolower(trim($email));
        $key = self::throttleKey($email, $ip);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->log('emergency_login_throttled', $email, $ip);
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw ValidationException::withMessages([
                self::ERROR_FIELD => "Demasiados intentos fallidos. Intenta de nuevo en {$minutes} minutos.",
            ]);
        }

        $user = $email === self::configuredEmail()
            ? User::where('email', $email)->first()
            : null;

        if ($user?->isLocked()) {
            RateLimiter::hit($key, self::LOCK_MINUTES * 60);
            $this->log('emergency_login_locked', $email, $ip, $user);

            if ($this->throttleJustReached($key)) {
                $this->alertLockout($ip);
            }

            throw $this->genericFailure();
        }

        // Always hash-check (against a dummy hash when there is no account)
        // to keep the response time independent of the account existing.
        $valid = Hash::check($password, $user->password ?? Hash::make('emergency-login-dummy'));

        if ($user === null || ! $valid) {
            RateLimiter::hit($key, self::LOCK_MINUTES * 60);
            $locked = $this->registerFailure($user);
            $this->log('emergency_login_failed', $email, $ip, $user);

            // Only the emergency account (`$user` is null for any other
            // username) alerts, once per lockout: persistent lock or the
            // per-IP limiter reached on this attempt.
            if ($user !== null && ($locked || $this->throttleJustReached($key))) {
                $this->alertLockout($ip);
            }

            throw $this->genericFailure();
        }

        // Deactivated in Administración › Usuarios. Only told after a valid
        // password, so it reveals nothing to someone guessing.
        if (! $user->isActive()) {
            $this->log('emergency_login_inactive', $email, $ip, $user);

            throw ValidationException::withMessages([self::ERROR_FIELD => self::INACTIVE_ERROR]);
        }

        RateLimiter::clear($key);

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();

        $this->log('emergency_login_succeeded', $email, $ip, $user);

        return $user;
    }

    /**
     * Counts the failure on the account; true when it locks it now.
     */
    private function registerFailure(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $attempts = $user->failed_login_attempts + 1;
        $locks = $attempts >= self::MAX_ATTEMPTS;

        // Reaching the limit locks the account and restarts the counter, so
        // after the lock expires the account gets a fresh set of attempts.
        $user->forceFill($locks
            ? ['failed_login_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)]
            : ['failed_login_attempts' => $attempts]
        )->save();

        return $locks;
    }

    /**
     * True when the attempt just counted is the one that blocks this
     * username + IP (later attempts never reach the hit: they are throttled).
     */
    private function throttleJustReached(string $key): bool
    {
        return RateLimiter::attempts($key) === self::MAX_ATTEMPTS;
    }

    /**
     * Critical `auth` alert for the alert recipients (bell, Telegram, mail),
     * grouped under one key. Never includes the typed password.
     */
    private function alertLockout(string $ip): void
    {
        $this->alerts->raise(
            level: Alert::LEVEL_CRITICAL,
            source: 'auth',
            title: 'Cuenta de emergencia bloqueada',
            body: sprintf(
                'La cuenta de emergencia quedó bloqueada %d minutos después de %d intentos fallidos. Último intento desde la IP %s. Si no fue alguien de TI, revisa el acceso y cambia la contraseña.',
                self::LOCK_MINUTES,
                self::MAX_ATTEMPTS,
                $ip,
            ),
            groupKey: self::LOCKOUT_GROUP_KEY,
        );
    }

    private function genericFailure(): ValidationException
    {
        return ValidationException::withMessages([self::ERROR_FIELD => self::GENERIC_ERROR]);
    }

    private function log(string $event, string $email, string $ip, ?User $user = null): void
    {
        $activity = activity('auth')->event($event)->withProperties(['email' => $email, 'ip' => $ip]);

        if ($user !== null) {
            $activity->performedOn($user)->causedBy($user);
        }

        $activity->log(match ($event) {
            'emergency_login_succeeded' => 'Ingreso de emergencia exitoso',
            'emergency_login_locked' => 'Ingreso de emergencia rechazado: cuenta bloqueada',
            'emergency_login_throttled' => 'Ingreso de emergencia rechazado: demasiados intentos',
            'emergency_login_inactive' => 'Ingreso de emergencia rechazado: cuenta desactivada',
            default => 'Ingreso de emergencia fallido',
        });
    }
}
