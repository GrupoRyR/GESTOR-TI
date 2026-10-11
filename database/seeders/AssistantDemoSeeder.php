<?php

namespace Database\Seeders;

use App\Models\AiProduct;
use App\Models\Area;
use App\Models\Company;
use App\Models\KnowledgeItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local-development sample of the Asistente TI knowledge base, shaped like
 * the design fixtures (resources/js/mocks/assistant-fixtures.ts). NOT part
 * of DatabaseSeeder: run it by hand (`php artisan db:seed
 * --class=AssistantDemoSeeder`) after DatabaseSeeder (and optionally
 * AiDemoSeeder, for the related product). Idempotent by title. Unknown
 * facts stay as bracket placeholders: nothing here is real company data.
 */
class AssistantDemoSeeder extends Seeder
{
    public function run(): void
    {
        $companies = Company::query()->orderBy('id')->get();
        $first = $companies->first();

        if ($first === null) {
            $this->command?->warn('No hay empresas: corre primero DatabaseSeeder.');

            return;
        }

        $all = $companies->pluck('id')->all();
        $ventas = $this->area($first, 'Ventas');
        $cartera = $this->area($first, 'Cartera');
        $ti = $this->area($first, 'TI');

        $report = $this->card('Informe de ventas diario', [$first->id], $ventas, 'company', '2027-01-15', 'report', [
            'shows' => 'Los pedidos del día anterior, tomados de SAP B1',
            'source_system' => 'Toma Pedidos SAP, con datos de SAP Business One',
            'recipients' => 'Gerencia y coordinadores de ventas',
            'schedule' => 'Todos los días a las 6:00 a. m.',
            'format' => 'Excel adjunto por correo',
        ], 'Lo envía Toma Pedidos SAP a las 6:00 a. m. a gerencia y a los coordinadores de ventas.');

        $this->card('SAP Business One', $all, $ti, 'it', '2026-09-30', 'system', [
            'what_is' => 'ERP de las empresas del grupo',
            'runs_on' => 'Servidor local',
            'access' => 'Por VPN, con usuario de SAP',
            'version' => '[VERSIÓN]',
        ], 'ERP del grupo. Corre en un servidor local y se entra por VPN.');

        $this->card('Pedidos de Toma Pedidos a SAP B1', [$first->id], $ventas, 'it', '2026-11-30', 'data_flow', [
            'origin_destination' => 'Toma Pedidos a SAP B1',
            'transport' => 'Service Layer de SAP B1',
            'frequency' => '[FRECUENCIA]',
            'on_failure' => 'El pedido queda “pendiente de sincronizar”',
        ]);

        $this->card('Cómo sacar el informe de antigüedad de cartera en SAP B1', [$first->id], $cartera, 'company', '2026-12-20', 'procedure', [
            'steps' => ['Abrir SAP Business One.', 'Ir a [RUTA EN SAP].', '[PASOS SIGUIENTES]'],
            'who_can' => 'Gerencia de cartera y asesores',
        ]);

        $this->card('Conectarse a la VPN', $all, $ti, 'company', '2027-02-10', 'procedure', [
            'steps' => ['[PASOS PARA CONECTARSE]'],
            'who_can' => '[QUIÉN LO PUEDE HACER]',
        ]);

        $this->card('Pedido bloqueado por cartera', [$first->id], $ventas, 'company', '2027-03-05', 'glossary', [
            'term' => 'Pedido bloqueado por cartera',
            'definition' => 'Pedido que SAP retiene porque el cliente superó su cupo o tiene facturas vencidas',
            'also_called' => 'pedido retenido',
        ]);

        $product = AiProduct::query()->where('slug', 'toma-pedidos')->first();
        if ($product !== null && ! DB::table('knowledge_links')->where('knowledge_item_id', $report->id)->where('ai_product_id', $product->id)->exists()) {
            DB::table('knowledge_links')->insert(['knowledge_item_id' => $report->id, 'ai_product_id' => $product->id, 'position' => 0]);
        }
    }

    private function area(Company $company, string $name): Area
    {
        return Area::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'slug' => Str::slug($name)],
            ['name' => $name, 'is_active' => true],
        );
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $fields
     */
    private function card(string $title, array $companyIds, Area $area, string $visibility, string $reviewAt, string $type, array $fields, ?string $description = null): KnowledgeItem
    {
        $card = KnowledgeItem::query()->firstOrCreate(['title' => $title], [
            'type' => $type,
            'description' => $description,
            'fields' => $fields,
            'visibility' => $visibility,
            'area_id' => $area->id,
            'review_at' => $reviewAt,
            'source' => 'manual',
        ]);
        $card->companies()->syncWithoutDetaching($companyIds);

        return $card;
    }
}
