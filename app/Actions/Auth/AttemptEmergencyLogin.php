<?php

namespace App\Actions\Auth;

use App\Models\User;
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
 * password is never logged.
 */
class AttemptEmergencyLogin
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    public const GENERIC_ERROR = 'Las credenciales no son válidas.';

    /** Form field that carries the credential errors (`Auth/Emergency`). */
    public const ERROR_FIELD = 'password';

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

            throw $this->genericFailure();
        }

        // Always hash-check (against a dummy hash when there is no account)
        // to keep the response time independent of the account existing.
        $valid = Hash::check($password, $user->password ?? Hash::make('emergency-login-dummy'));

        if ($user === null || ! $valid) {
            RateLimiter::hit($key, self::LOCK_MINUTES * 60);
            $this->registerFailure($user);
            $this->log('emergency_login_failed', $email, $ip, $user);

            throw $this->genericFailure();
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

    private function registerFailure(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $attempts = $user->failed_login_attempts + 1;

        // Reaching the limit locks the account and restarts the counter, so
        // after the lock expires the account gets a fresh set of attempts.
        $user->forceFill($attempts >= self::MAX_ATTEMPTS
            ? ['failed_login_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)]
            : ['failed_login_attempts' => $attempts]
        )->save();
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
            default => 'Ingreso de emergencia fallido',
        });
    }
}
