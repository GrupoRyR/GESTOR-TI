<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Support\CompanyLogos;
use Illuminate\Database\Seeder;

/**
 * Companies of Grupo Empresarial RYR. Idempotent (keyed by slug); more
 * companies can be added here or from Administración.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $companies = [
            [
                'slug' => 'segurex',
                'name' => 'Segurex Latam',
                'short_name' => 'Segurex',
                'tenant_id' => '97cd024c-cd7a-419b-b385-f47b5f5816c8',
                'domains' => ['segurex.com'],
                'colors' => ['primary' => '#FF671F', 'accent' => '#666666', 'dark' => '#333333'],
                'sender_email' => 'segurex.info@segurex.com',
                'sender_name' => 'Segurex Latam',
                'is_active' => true,
            ],
            [
                'slug' => 'derivados',
                'name' => 'Soluciones y Derivados de Colombia S.A.S.',
                'short_name' => 'Derivados',
                'tenant_id' => '54f53029-e6e2-4b5f-a862-3454ae886468',
                'domains' => ['derivadoscol.com'],
                'colors' => ['primary' => '#183F94', 'accent' => '#6D94E8', 'dark' => '#031D54'],
                'sender_email' => 'derivados.info@derivadoscol.com',
                'sender_name' => 'Soluciones y Derivados de Colombia',
                'is_active' => true,
            ],
        ];

        foreach ($companies as $company) {
            $model = Company::updateOrCreate(['slug' => $company['slug']], $company);

            $this->publishBrandLogos($model);
        }
    }

    /**
     * Publishes `resources/brand/logo-{slug}.svg` and `logo-{slug}-dark.svg`
     * to the public disk (`companies/{id}/`) as the company's logos, only
     * when it has none: logos uploaded from Administración are kept.
     * Requires `php artisan storage:link` on the server to be served.
     */
    private function publishBrandLogos(Company $company): void
    {
        CompanyLogos::publishIfMissing($company, 'logo', resource_path('brand/logo-'.$company->slug.'.svg'));
        CompanyLogos::publishIfMissing($company, 'logo_dark', resource_path('brand/logo-'.$company->slug.'-dark.svg'));
    }
}
