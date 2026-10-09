<?php

namespace App\Jobs;

use App\Actions\Employees\SyncEmployeesFromGraph;
use App\Models\Company;
use App\Support\EmployeeSyncStatus;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Daily Microsoft Graph sync of one company's employees (database queue).
 * Inactive companies and companies without tenant are skipped.
 *
 * Every run records its outcome (`App\Support\EmployeeSyncStatus`), shown
 * in Colaboradores; a failed attempt records a Spanish message and is
 * rethrown so the queue retries it.
 */
class SyncCompanyEmployees implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * Seconds to wait before retrying a failed attempt.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $companyId) {}

    /**
     * One sync per company at a time (scheduler + "Sincronizar" button).
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-employees:'.$this->companyId))->releaseAfter(120)->expireAfter(900)];
    }

    /**
     * @return array{created: int, updated: int, deactivated: int}
     */
    public function handle(SyncEmployeesFromGraph $sync, CurrentCompany $currentCompany, EmployeeSyncStatus $status): array
    {
        $company = Company::find($this->companyId);

        if ($company === null || ! $company->isSyncable()) {
            return ['created' => 0, 'updated' => 0, 'deactivated' => 0];
        }

        try {
            $result = $currentCompany->runAs($company->id, fn (): array => $sync->handle($company));
        } catch (Throwable $exception) {
            $status->recordFailure($company->id, EmployeeSyncStatus::messageFor($exception));

            throw $exception;
        }

        $status->recordSuccess($company->id, $result);

        return $result;
    }
}
