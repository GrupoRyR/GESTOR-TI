<?php

namespace Database\Seeders;

use App\Enums\Ai\ProductStatus;
use App\Models\AiProduct;
use App\Models\Area;
use App\Models\Company;
use App\Models\MonitoredApp;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The own apps in use today (PROMPT §1), the first Gestor IA products.
 * Slugs, descriptions and areas follow the design fixtures; owners,
 * versions, URLs, repositories and costs are pending from Jhon.
 *
 * Idempotent and create-only (keyed by slug, soft-deleted rows included):
 * edits made from the app are never overwritten. Each product gets its
 * `monitored_apps` row (same slug); an existing row is only linked.
 * Companies or areas missing from the table are skipped.
 */
class AiProductSeeder extends Seeder
{
    /**
     * @var list<array{slug: string, name: string, company: string, description: string, stack: string, hosting: string, monitor: string, areas: list<string>}>
     */
    public const PRODUCTS = [
        [
            'slug' => 'toma-pedidos',
            'name' => 'Toma Pedidos SAP',
            'company' => 'segurex',
            'description' => 'Pedidos de los asesores directo a SAP Business One.',
            'stack' => 'Laravel',
            'hosting' => 'GoDaddy',
            'monitor' => MonitoredApp::TYPE_WEB,
            'areas' => ['Ventas'],
        ],
        [
            'slug' => 'portafolio',
            'name' => 'Portafolio de productos',
            'company' => 'derivados',
            'description' => 'Catálogo de productos con fichas técnicas y precios.',
            'stack' => 'PHP',
            'hosting' => 'GoDaddy',
            'monitor' => MonitoredApp::TYPE_WEB,
            'areas' => ['Compras', 'Ventas'],
        ],
        [
            'slug' => 'cuentas-por-pagar',
            'name' => 'Cuentas por pagar',
            'company' => 'segurex',
            'description' => 'Radicación y seguimiento de facturas de proveedores.',
            'stack' => 'Next.js',
            'hosting' => 'Vercel',
            'monitor' => MonitoredApp::TYPE_WEB,
            'areas' => ['Contabilidad'],
        ],
        [
            'slug' => 'visualizador-cartera',
            'name' => 'Visualizador de cartera',
            'company' => 'segurex',
            'description' => 'Consulta en línea de la cartera por cliente, edad y asesor.',
            'stack' => 'Next.js',
            'hosting' => 'Vercel',
            'monitor' => MonitoredApp::TYPE_WEB,
            'areas' => ['Cartera', 'Ventas'],
        ],
        [
            'slug' => 'robotcamara',
            'name' => 'Cámaras',
            'company' => 'segurex',
            'description' => 'Robot local que revisa y guarda las grabaciones de las cámaras.',
            'stack' => 'Python',
            'hosting' => 'Equipo local',
            'monitor' => MonitoredApp::TYPE_LOCAL,
            'areas' => [],
        ],
    ];

    public function run(): void
    {
        foreach (self::PRODUCTS as $seed) {
            $company = Company::query()->where('slug', $seed['company'])->first();

            $product = AiProduct::withTrashed()->firstOrCreate(['slug' => $seed['slug']], [
                'name' => $seed['name'],
                'description' => $seed['description'],
                'stack' => $seed['stack'],
                'hosting' => $seed['hosting'],
                'status' => ProductStatus::Production,
            ]);

            if ($product->wasRecentlyCreated && $company !== null) {
                $product->companies()->attach($company->id, ['is_primary' => true]);
                $this->attachAreas($product, $company, $seed['areas']);
            }

            $this->linkMonitoredApp($product, $seed['monitor'], $company);
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function attachAreas(AiProduct $product, Company $company, array $names): void
    {
        foreach ($names as $i => $name) {
            $area = Area::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('slug', Str::slug($name))
                ->first();

            if ($area !== null) {
                $product->areas()->attach($area->id, ['is_primary' => $i === 0]);
            }
        }
    }

    private function linkMonitoredApp(AiProduct $product, string $type, ?Company $company): void
    {
        $app = MonitoredApp::query()->where('slug', $product->slug)->first()
            ?? MonitoredApp::query()->where('ai_product_id', $product->id)->first();

        if ($app === null) {
            MonitoredApp::create([
                'company_id' => $company?->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'type' => $type,
                'ai_product_id' => $product->id,
            ]);

            return;
        }

        if ($app->ai_product_id === null) {
            $app->forceFill(['ai_product_id' => $product->id])->save();
        }
    }
}
