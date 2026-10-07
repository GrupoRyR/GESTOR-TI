<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración › Empresas. Changes are audited through activitylog
 * (`Company` uses `LogsActivity`). Logo upload is pending: only paths.
 */
class CompanyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Companies/Index', [
            'companies' => Company::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Company $company) => $this->present($company))
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        Company::create($request->validated());

        return back()->with('success', 'Empresa creada.');
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        $company->update($request->validated());

        return back()->with('success', 'Empresa actualizada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'short_name' => $company->short_name,
            'slug' => $company->slug,
            'tenant_id' => $company->tenant_id,
            'domains' => $company->domains ?? [],
            'logo_path' => $company->logo_path,
            'logo_dark_path' => $company->logo_dark_path,
            'logo_url' => $company->logo_url,
            'logo_dark_url' => $company->logo_dark_url,
            'colors' => $company->brandColors(),
            'sender_email' => $company->sender_email,
            'sender_name' => $company->sender_name,
            'is_active' => $company->is_active,
        ];
    }
}
