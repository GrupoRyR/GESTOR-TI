<?php

namespace App\Services\Credentials;

use App\Models\EmployeeAccess;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Single entry point to read employee access passwords.
 *
 * Used by the "Revelar" button (channel `web`) and, in Stage C, by the
 * Asistente TI tool `entregar_credencial` (`telegram` / `assistant`): the
 * assistant only identifies WHICH access with `findForDelivery()` (no
 * passwords) and the app sends the value returned by `reveal()` straight to
 * the requester, never to the AI provider.
 */
class EmployeeCredentialService
{
    public const PERMISSION = 'credentials.view';

    public const CHANNELS = ['web', 'telegram', 'assistant'];

    public const MAX_CANDIDATES = 10;

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * Plain password of the access. Requires `credentials.view` (and, for a
     * user without `companies.view_all`, an access of their own company).
     * Every reveal is written to the activity log (`credential_revealed`).
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException for an unknown channel
     */
    public function reveal(User $actor, EmployeeAccess $access, string $channel): string
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Unknown credential channel [{$channel}].");
        }

        if (! $actor->can(self::PERMISSION)) {
            throw new AuthorizationException('No tienes permiso para ver contraseñas.');
        }

        $access->loadMissing([
            'employee' => fn ($query) => $query->withoutGlobalScopes(),
            'system',
        ]);
        $employee = $access->employee;

        if ($employee === null
            || (! $this->currentCompany->canViewAll($actor) && $employee->company_id !== $actor->company_id)) {
            throw new AuthorizationException('No tienes permiso para ver esta contraseña.');
        }

        activity('credentials')
            ->causedBy($actor)
            ->performedOn($access)
            ->event('credential_revealed')
            ->withProperties([
                'channel' => $channel,
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'company_id' => $employee->company_id,
                'access_system_id' => $access->access_system_id,
                'access_system' => $access->system?->name,
                'username' => $access->username,
            ])
            ->log('credential_revealed');

        return $access->password;
    }

    /**
     * Candidate accesses for a delivery request ("la contraseña de Office de
     * Juan Pérez"), WITHOUT the password column, so the caller can ask to
     * choose when there are several. Only active employees; every word of
     * the employee query must match the name or the email. Honours the
     * current company context (CompanyScope on employees).
     *
     * @return Collection<int, EmployeeAccess>
     */
    public function findForDelivery(string $employeeQuery, string $systemQuery): Collection
    {
        $words = array_values(array_filter(explode(' ', Str::squish($employeeQuery))));
        $system = Str::squish($systemQuery);

        if ($words === [] || $system === '') {
            return collect();
        }

        return EmployeeAccess::query()
            ->select(['id', 'employee_id', 'access_system_id', 'username', 'notes', 'last_rotated_at'])
            ->whereHas('employee', function ($query) use ($words): void {
                $query->where('status', 'active');

                foreach ($words as $word) {
                    $like = '%'.$word.'%';
                    $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
                }
            })
            ->whereHas('system', fn ($query) => $query
                ->where('is_active', true)
                ->where('name', 'like', '%'.$system.'%'))
            ->with(['employee:id,company_id,name,email,job_title', 'system:id,name'])
            ->orderBy('employee_id')
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->toBase();
    }
}
