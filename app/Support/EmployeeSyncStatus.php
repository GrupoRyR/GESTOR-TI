<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Result of the last Microsoft Graph employee sync per company, stored as
 * the setting `employees.last_sync.{company_id}`:
 * `{last_success_at, error, failed_at, created, updated, deactivated}`.
 *
 * Written by `App\Jobs\SyncCompanyEmployees` (every attempt), read by
 * Colaboradores (`last_sync_at`, `sync_error`). A failure keeps the last
 * success; a success clears the error.
 */
class EmployeeSyncStatus
{
    public const KEY_PREFIX = 'employees.last_sync.';

    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array{created: int, updated: int, deactivated: int}  $result
     */
    public function recordSuccess(int $companyId, array $result): void
    {
        $this->settings->set(self::KEY_PREFIX.$companyId, [
            'last_success_at' => now()->toIso8601String(),
            'error' => null,
            'failed_at' => null,
            'created' => (int) ($result['created'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'deactivated' => (int) ($result['deactivated'] ?? 0),
        ]);
    }

    public function recordFailure(int $companyId, string $message): void
    {
        $previous = $this->get($companyId);

        $this->settings->set(self::KEY_PREFIX.$companyId, [
            'last_success_at' => $previous['last_success_at'] ?? null,
            'error' => $message,
            'failed_at' => now()->toIso8601String(),
            'created' => (int) ($previous['created'] ?? 0),
            'updated' => (int) ($previous['updated'] ?? 0),
            'deactivated' => (int) ($previous['deactivated'] ?? 0),
        ]);
    }

    /**
     * Spanish, user-facing message for a failed sync. Raw exception messages
     * stay in the logs (they may carry tenant ids or URLs).
     */
    public static function messageFor(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            if (in_array($status, [401, 403], true)) {
                return 'Microsoft rechazó el acceso al directorio. Un administrador de Microsoft de la empresa debe aprobar el permiso User.Read.All; después vuelve a intentar. Código graph_'.$status.'.';
            }

            if ($status === 429) {
                return 'Microsoft limitó las consultas al directorio. Vuelve a intentar en unos minutos. Código graph_429.';
            }

            return 'Microsoft respondió con un error al leer el directorio. Vuelve a intentar más tarde. Código graph_'.$status.'.';
        }

        if ($exception instanceof ConnectionException) {
            return 'No se pudo conectar con Microsoft. Vuelve a intentar más tarde. Código graph_connection.';
        }

        return 'No se pudo sincronizar con Microsoft. Revisa la integración (credenciales de la aplicación e inquilino) y vuelve a intentar. Código sync_failed.';
    }

    /**
     * What Colaboradores shows for the given companies: one company → its
     * own state; several ("Todas") → the oldest last success (null when any
     * company never synced) and every error prefixed by the company.
     *
     * @param  iterable<Company>  $companies
     * @return array{last_sync_at: ?string, sync_error: ?string}
     */
    public function summary(iterable $companies): array
    {
        $companies = collect($companies)->values();

        if ($companies->isEmpty()) {
            return ['last_sync_at' => null, 'sync_error' => null];
        }

        $states = $companies->map(fn (Company $company): array => [$company, $this->get($company->id)]);
        $successes = $states->map(fn (array $state): ?string => $state[1]['last_success_at'] ?? null);

        $lastSync = $successes->contains(null)
            ? null
            : $successes->sortBy(fn (string $at) => strtotime($at))->first();

        $errors = $states
            ->filter(fn (array $state): bool => filled($state[1]['error'] ?? null))
            ->map(fn (array $state): string => $companies->count() > 1
                ? $state[0]->short_name.': '.$state[1]['error']
                : (string) $state[1]['error']);

        return [
            'last_sync_at' => $lastSync,
            'sync_error' => $errors->isEmpty() ? null : $errors->implode("\n"),
        ];
    }

    /**
     * Stored state of the company (empty when it never synced).
     *
     * @return array<string, mixed>
     */
    public function get(int $companyId): array
    {
        return (array) $this->settings->get(self::KEY_PREFIX.$companyId, []);
    }
}
