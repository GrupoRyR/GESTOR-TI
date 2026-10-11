<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CompanyRequest;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Support\CompanyLogos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración › Empresas (`Admin/Companies/Index`, `Admin/Companies/Create`).
 * Changes are audited through activitylog (`Company` uses `LogsActivity`).
 * The list is sent as `admin_companies`: a page prop named `companies`
 * would overwrite the shared company selector.
 */
class CompanyController extends Controller
{
    /**
     * Wizard step (companyWizard.ts `FIELD_STEP`) that owns each field;
     * unknown fields go to the review step (5).
     */
    private const FIELD_STEP = [
        'name' => 1,
        'short_name' => 1,
        'slug' => 1,
        'domains' => 1,
        'tenant_id' => 2,
        'logo' => 3,
        'logo_dark' => 3,
        'colors' => 3,
        'sender_email' => 4,
    ];

    public function index(Request $request): Response
    {
        return $this->renderIndex($request, null);
    }

    public function edit(Request $request, Company $company): Response
    {
        return $this->renderIndex($request, $company);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can(CompanyRequest::MANAGE_TENANT_PERMISSION), 403);

        $props = ['admin_consent_url_template' => $this->adminConsentUrlTemplate()];

        $step = $this->initialStep($request);

        if ($step !== null) {
            $props['initial_step'] = $step;
        }

        return Inertia::render('Admin/Companies/Create', $props);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $company = DB::transaction(function () use ($request): Company {
            $company = Company::create([
                ...$request->attributesForCompany(),
                'sender_name' => $request->validated('name'),
            ]);

            $this->storeLogos($request, $company);

            return $company;
        });

        return redirect()->route('admin.companies.edit', $company)->with('success', 'Empresa creada.');
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        $company->fill($request->attributesForCompany());
        $this->storeLogos($request, $company);

        return redirect()->route('admin.companies.edit', $company)->with('success', 'Empresa actualizada.');
    }

    /**
     * Saves the company with the uploaded logos and deletes the files they
     * replace once the new paths are stored.
     */
    private function storeLogos(CompanyRequest $request, Company $company): void
    {
        $replaced = [];

        foreach (['logo', 'logo_dark'] as $field) {
            $file = $request->file($field);

            if ($file !== null) {
                $replaced[] = CompanyLogos::put($company, $field, $file);
            }
        }

        $company->save();

        foreach ($replaced as $path) {
            CompanyLogos::delete($path);
        }
    }

    private function renderIndex(Request $request, ?Company $selected): Response
    {
        $companies = Company::query()
            ->withCount(['employees' => fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('status', Employee::STATUS_ACTIVE)])
            ->orderBy('name')
            ->get();

        $selectedRow = $selected === null
            ? null
            : $companies->firstWhere('id', $selected->id);

        return Inertia::render('Admin/Companies/Index', [
            'admin_companies' => $companies->map(fn (Company $company) => $this->present($company))->values()->all(),
            'selected' => $selectedRow === null ? null : $this->present($selectedRow),
            'can_change_tenant' => (bool) $request->user()?->can(CompanyRequest::MANAGE_TENANT_PERMISSION),
        ]);
    }

    /**
     * `AdminCompany` (resources/js/types/pages/admin.ts).
     *
     * @return array<string, mixed>
     */
    private function present(Company $company): array
    {
        return [
            ...$company->toSharedArray(),
            'tenant_id' => (string) $company->tenant_id,
            'domains' => array_values($company->domains ?? []),
            'sender_email' => (string) $company->sender_email,
            'active' => $company->is_active,
            'employees_count' => (int) $company->getAttribute('employees_count'),
        ];
    }

    /**
     * Microsoft admin-consent URL with the `{tenant}` slot the wizard fills.
     */
    private function adminConsentUrlTemplate(): string
    {
        return str_replace(
            '{client_id}',
            rawurlencode((string) config('services.microsoft.client_id')),
            (string) config('gestor.microsoft.admin_consent_url_template'),
        );
    }

    /**
     * After a failed `admin.companies.store`, the first wizard step with an
     * error; null when there are no errors.
     */
    private function initialStep(Request $request): ?int
    {
        $errors = $request->session()->get('errors');

        if (! $errors instanceof ViewErrorBag) {
            return null;
        }

        $keys = $errors->getBag('default')->keys();

        if ($keys === []) {
            return null;
        }

        return min(array_map(
            fn (string $key) => self::FIELD_STEP[$key] ?? self::FIELD_STEP[explode('.', $key)[0]] ?? 5,
            $keys,
        ));
    }
}
