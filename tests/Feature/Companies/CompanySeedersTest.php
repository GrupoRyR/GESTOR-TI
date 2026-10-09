<?php

namespace Tests\Feature\Companies;

use App\Models\Area;
use App\Models\Company;
use Database\Seeders\AreaSeeder;
use Database\Seeders\CompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanySeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_are_idempotent(): void
    {
        $this->seed([CompanySeeder::class, AreaSeeder::class]);
        $this->seed([CompanySeeder::class, AreaSeeder::class]);

        $this->assertSame(2, Company::count());

        foreach (Company::all() as $company) {
            $this->assertSame(9, Area::withoutGlobalScopes()->where('company_id', $company->id)->count());
        }
    }

    public function test_company_data_is_seeded(): void
    {
        $this->seed(CompanySeeder::class);

        $segurex = Company::where('slug', 'segurex')->firstOrFail();
        $this->assertSame('Segurex Latam', $segurex->name);
        $this->assertSame('97cd024c-cd7a-419b-b385-f47b5f5816c8', $segurex->tenant_id);
        $this->assertSame(['segurex.com'], $segurex->domains);
        $this->assertSame('#FF671F', $segurex->colors['primary']);
        $this->assertSame('segurex.info@segurex.com', $segurex->sender_email);
        $this->assertTrue($segurex->is_active);

        $derivados = Company::where('slug', 'derivados')->firstOrFail();
        $this->assertSame('Derivados', $derivados->short_name);
        $this->assertSame('54f53029-e6e2-4b5f-a862-3454ae886468', $derivados->tenant_id);
        $this->assertSame(['primary' => '#183F94', 'accent' => '#6D94E8', 'dark' => '#031D54'], $derivados->colors);
    }

    public function test_brand_logos_are_published_when_the_company_has_none(): void
    {
        Storage::fake('public');

        $this->seed(CompanySeeder::class);

        $segurex = Company::where('slug', 'segurex')->firstOrFail();
        $this->assertSame('companies/'.$segurex->id.'/logo-segurex.svg', $segurex->logo_path);
        $this->assertSame('companies/'.$segurex->id.'/logo-segurex-dark.svg', $segurex->logo_dark_path);
        Storage::disk('public')->assertExists([$segurex->logo_path, $segurex->logo_dark_path]);
        $this->assertSame(
            file_get_contents(resource_path('brand/logo-segurex.svg')),
            Storage::disk('public')->get($segurex->logo_path),
        );

        $derivados = Company::where('slug', 'derivados')->firstOrFail();
        $this->assertSame('companies/'.$derivados->id.'/logo-derivados.svg', $derivados->logo_path);
        $this->assertSame('companies/'.$derivados->id.'/logo-derivados-dark.svg', $derivados->logo_dark_path);
    }

    public function test_seeder_keeps_logos_uploaded_from_administracion(): void
    {
        Storage::fake('public');
        $this->seed(CompanySeeder::class);

        $segurex = Company::where('slug', 'segurex')->firstOrFail();
        $segurex->update(['logo_path' => 'companies/'.$segurex->id.'/uploaded.png']);

        $this->seed(CompanySeeder::class);

        $this->assertSame('companies/'.$segurex->id.'/uploaded.png', $segurex->fresh()->logo_path);
    }

    public function test_area_seeder_covers_every_company_including_new_ones(): void
    {
        $this->seed(CompanySeeder::class);
        Company::factory()->create(['slug' => 'tercera']);

        $this->seed(AreaSeeder::class);

        $tercera = Company::where('slug', 'tercera')->firstOrFail();
        $this->assertSame(9, Area::withoutGlobalScopes()->where('company_id', $tercera->id)->count());
        $this->assertTrue(
            Area::withoutGlobalScopes()->where('company_id', $tercera->id)->where('slug', 'tesoreria')->exists(),
        );
    }
}
