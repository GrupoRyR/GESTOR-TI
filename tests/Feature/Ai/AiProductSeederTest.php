<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\ProductStatus;
use App\Models\AiProduct;
use App\Models\Company;
use App\Models\MonitoredApp;
use Database\Seeders\AiProductSeeder;
use Database\Seeders\AreaSeeder;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProductSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CompanySeeder::class, AreaSeeder::class]);
    }

    public function test_it_seeds_the_five_current_apps_with_their_monitored_apps_idempotently(): void
    {
        $this->seed(AiProductSeeder::class);
        $this->seed(AiProductSeeder::class);

        $this->assertSame(5, AiProduct::count());
        $this->assertSame(5, MonitoredApp::count());

        $expected = [
            'toma-pedidos' => ['Toma Pedidos SAP', 'segurex', 'Laravel', 'GoDaddy', MonitoredApp::TYPE_WEB],
            'portafolio' => ['Portafolio de productos', 'derivados', 'PHP', 'GoDaddy', MonitoredApp::TYPE_WEB],
            'cuentas-por-pagar' => ['Cuentas por pagar', 'segurex', 'Next.js', 'Vercel', MonitoredApp::TYPE_WEB],
            'visualizador-cartera' => ['Visualizador de cartera', 'segurex', 'Next.js', 'Vercel', MonitoredApp::TYPE_WEB],
            'robotcamara' => ['Cámaras', 'segurex', 'Python', 'Equipo local', MonitoredApp::TYPE_LOCAL],
        ];

        foreach ($expected as $slug => [$name, $company, $stack, $hosting, $type]) {
            $product = AiProduct::query()->where('slug', $slug)->firstOrFail();

            $this->assertSame($name, $product->name);
            $this->assertSame($stack, $product->stack);
            $this->assertSame($hosting, $product->hosting);
            $this->assertSame(ProductStatus::Production, $product->status);
            $this->assertNull($product->owner_user_id, 'owners pending from Jhon');
            $this->assertNotSame('', (string) $product->description);
            $this->assertSame([$company], $product->companies->pluck('slug')->all());
            $this->assertTrue($product->primaryCompany()->is(Company::where('slug', $company)->first()));

            $app = $product->monitoredApp;
            $this->assertNotNull($app, $slug);
            $this->assertSame($slug, $app->slug);
            $this->assertSame($type, $app->type);
            $this->assertSame($product->primaryCompany()->id, $app->company_id);
            $this->assertFalse($product->health()['monitoring_active']);
        }

        $this->assertSame(['Compras', 'Ventas'], AiProduct::where('slug', 'portafolio')->first()->areas->pluck('name')->sort()->values()->all());
    }

    public function test_it_keeps_edits_and_links_an_existing_monitored_app(): void
    {
        $existing = MonitoredApp::create(['slug' => 'robotcamara', 'name' => 'Robot cámara', 'type' => MonitoredApp::TYPE_LOCAL]);

        $this->seed(AiProductSeeder::class);

        $product = AiProduct::where('slug', 'robotcamara')->firstOrFail();
        $this->assertSame($product->id, $existing->fresh()->ai_product_id);
        $this->assertSame('Robot cámara', $existing->fresh()->name, 'existing monitored app kept');

        $product->update(['name' => 'Cámaras (robot)', 'owner_name' => 'Gerencia']);
        $product->delete();

        $this->seed(AiProductSeeder::class);

        $this->assertSame(5, AiProduct::withTrashed()->count());
        $this->assertSame('Cámaras (robot)', AiProduct::withTrashed()->where('slug', 'robotcamara')->first()->name);
    }

    public function test_it_is_part_of_the_database_seeder(): void
    {
        $this->assertStringContainsString('AiProductSeeder', (string) file_get_contents((new \ReflectionClass(DatabaseSeeder::class))->getFileName()));
    }
}
