<?php

namespace App\Actions\Employees;

use App\Models\Company;
use App\Models\DepartmentAreaMapping;
use App\Models\Employee;
use App\Models\User;
use App\Services\Microsoft\GraphClient;
use Illuminate\Support\Str;

/**
 * Synchronizes the employees of one company from its Microsoft tenant.
 *
 * - Guests (`userType = Guest`) are skipped.
 * - Employees are upserted by `azure_id` inside the company.
 * - `accountEnabled = false`, and Graph employees no longer returned, become
 *   inactive (manual employees are never touched). An empty directory never
 *   deactivates anybody (protects against a broken or empty response).
 * - The area comes from the department mapping unless `area_locked`.
 * - Managers are resolved in a second pass (`manager_azure_id` → `manager_id`).
 * - `user_id` is linked to a user of the same company by azure id or email.
 *
 * Callers must run it inside the company context (CurrentCompany::runAs).
 */
class SyncEmployeesFromGraph
{
    public function __construct(private readonly GraphClient $graph) {}

    /**
     * @return array{created: int, updated: int, deactivated: int}
     */
    public function handle(Company $company): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'deactivated' => 0];
        $mappings = DepartmentAreaMapping::mapFor($company->id);
        $seen = [];

        foreach ($this->graph->listUsers((string) $company->tenant_id) as $graphUser) {
            $azureId = trim((string) ($graphUser['id'] ?? ''));

            if ($azureId === '' || strcasecmp((string) ($graphUser['userType'] ?? ''), 'Guest') === 0) {
                continue;
            }

            $seen[$azureId] = true;
            $outcome = $this->upsert($company, $azureId, $graphUser, $mappings);

            if ($outcome !== null) {
                $summary[$outcome]++;
            }
        }

        if ($seen !== []) {
            $summary['deactivated'] += $this->deactivateMissing($company, $seen);
        }

        $this->resolveManagers($company);

        activity('employees')
            ->performedOn($company)
            ->event('employees_synced')
            ->withProperties($summary + ['company' => $company->short_name])
            ->log('employees_synced');

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $graphUser
     * @param  array<string, int>  $mappings
     * @return 'created'|'updated'|'deactivated'|null
     */
    private function upsert(Company $company, string $azureId, array $graphUser, array $mappings): ?string
    {
        $employee = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('azure_id', $azureId)
            ->first() ?? new Employee(['company_id' => $company->id, 'azure_id' => $azureId]);

        $wasActive = $employee->exists && $employee->status === Employee::STATUS_ACTIVE;
        $email = $this->emailOf($graphUser);
        $department = $this->clean($graphUser['department'] ?? null);

        $employee->fill([
            'source' => Employee::SOURCE_GRAPH,
            'name' => $this->clean($graphUser['displayName'] ?? null) ?? $email ?? $azureId,
            'email' => $email,
            'job_title' => $this->clean($graphUser['jobTitle'] ?? null),
            'department' => $department,
            'manager_azure_id' => $this->clean($graphUser['manager']['id'] ?? null),
            'status' => ($graphUser['accountEnabled'] ?? true) === false
                ? Employee::STATUS_INACTIVE
                : Employee::STATUS_ACTIVE,
        ]);

        if (! $employee->area_locked) {
            $employee->area_id = $mappings[DepartmentAreaMapping::keyFor($department)] ?? null;
        }

        if ($employee->user_id === null) {
            $employee->user_id = $this->matchingUserId($company, $azureId, $email);
        }

        $isNew = ! $employee->exists;
        $changed = $isNew || $employee->isDirty();

        $employee->last_synced_at = now();
        $employee->save();

        return match (true) {
            $isNew => 'created',
            $wasActive && $employee->status === Employee::STATUS_INACTIVE => 'deactivated',
            $changed => 'updated',
            default => null,
        };
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function deactivateMissing(Company $company, array $seen): int
    {
        $count = 0;

        Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('source', Employee::SOURCE_GRAPH)
            ->where('status', Employee::STATUS_ACTIVE)
            ->lazyById()
            ->each(function (Employee $employee) use ($seen, &$count): void {
                if (! isset($seen[(string) $employee->azure_id])) {
                    $employee->update(['status' => Employee::STATUS_INACTIVE]);
                    $count++;
                }
            });

        return $count;
    }

    private function resolveManagers(Company $company): void
    {
        $ids = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNotNull('azure_id')
            ->pluck('id', 'azure_id')
            ->map(fn ($id): int => (int) $id);

        Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('source', Employee::SOURCE_GRAPH)
            ->lazyById()
            ->each(function (Employee $employee) use ($ids): void {
                $managerId = $employee->manager_azure_id === null ? null : ($ids[$employee->manager_azure_id] ?? null);
                $managerId = $managerId === (int) $employee->id ? null : $managerId;
                $current = $employee->manager_id === null ? null : (int) $employee->manager_id;

                if ($current !== $managerId) {
                    $employee->update(['manager_id' => $managerId]);
                }
            });
    }

    /**
     * User of the same company with the Azure id or the email, not yet
     * linked to another employee.
     */
    private function matchingUserId(Company $company, string $azureId, ?string $email): ?int
    {
        $user = User::query()
            ->where('company_id', $company->id)
            ->where(function ($query) use ($azureId, $email): void {
                $query->where('azure_id', $azureId);

                if ($email !== null) {
                    $query->orWhereRaw('lower(email) = ?', [$email]);
                }
            })
            ->whereNotIn('id', Employee::withoutGlobalScopes()->whereNotNull('user_id')->select('user_id'))
            ->orderByRaw('case when azure_id = ? then 0 else 1 end', [$azureId])
            ->first();

        return $user?->id;
    }

    /**
     * @param  array<string, mixed>  $graphUser
     */
    private function emailOf(array $graphUser): ?string
    {
        $email = $this->clean($graphUser['mail'] ?? null) ?? $this->clean($graphUser['userPrincipalName'] ?? null);

        return $email === null ? null : Str::lower($email);
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
