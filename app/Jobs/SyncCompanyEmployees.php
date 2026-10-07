<?php

namespace App\Jobs;

use App\Actions\Employees\SyncEmployeesFromGraph;
use App\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Daily Microsoft Graph sync of one company's employees (database queue).
 * Inactive companies and companies without tenant are skipped.
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
    public function handle(SyncEmployeesFromGraph $sync, CurrentCompany $currentCompany): array
    {
        $company = Company::find($this->companyId);

        if ($company === null || ! $company->isSyncable()) {
            return ['created' => 0, 'updated' => 0, 'deactivated' => 0];
        }

        return $currentCompany->runAs($company->id, fn (): array => $sync->handle($company));
    }
}
