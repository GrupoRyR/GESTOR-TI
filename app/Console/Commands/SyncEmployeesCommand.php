<?php

namespace App\Console\Commands;

use App\Jobs\SyncCompanyEmployees;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Queues the Microsoft Graph employee sync for one company (id or slug) or
 * for every active company with a tenant. Scheduled daily in routes/console.php.
 */
class SyncEmployeesCommand extends Command
{
    protected $signature = 'gestor:sync-employees {company? : ID o slug de la empresa (por defecto, todas las activas con inquilino)}';

    protected $description = 'Encola la sincronización de colaboradores desde Microsoft Graph';

    public function handle(): int
    {
        $argument = $this->argument('company');

        if ($argument === null || $argument === '') {
            $companies = Company::query()->syncable()->orderBy('id')->get();

            foreach ($companies as $company) {
                SyncCompanyEmployees::dispatch($company->id);
            }

            $this->info("Sincronización encolada para {$companies->count()} empresa(s).");

            return self::SUCCESS;
        }

        $company = Company::query()
            ->when(
                ctype_digit((string) $argument),
                fn ($query) => $query->whereKey((int) $argument),
                fn ($query) => $query->where('slug', $argument),
            )
            ->first();

        if ($company === null) {
            $this->error("No existe la empresa «{$argument}».");

            return self::FAILURE;
        }

        if (! $company->isSyncable()) {
            $this->error("La empresa «{$company->short_name}» está inactiva o no tiene inquilino de Microsoft.");

            return self::FAILURE;
        }

        SyncCompanyEmployees::dispatch($company->id);
        $this->info("Sincronización encolada para {$company->short_name}.");

        return self::SUCCESS;
    }
}
