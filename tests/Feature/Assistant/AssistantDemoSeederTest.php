<?php

namespace Tests\Feature\Assistant;

use App\Models\KnowledgeItem;
use Database\Seeders\AssistantDemoSeeder;
use Database\Seeders\DatabaseSeeder;

class AssistantDemoSeederTest extends AssistantTestCase
{
    public function test_it_seeds_sample_cards_idempotently(): void
    {
        $this->product(['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP']);

        $this->seed(AssistantDemoSeeder::class);
        $this->seed(AssistantDemoSeeder::class);

        $this->assertSame(6, KnowledgeItem::query()->count());
        $this->assertSame(['data_flow', 'glossary', 'procedure', 'report', 'system'], KnowledgeItem::query()->distinct()->orderBy('type')->pluck('type')->all());
        $report = KnowledgeItem::query()->where('title', 'Informe de ventas diario')->sole();
        $this->assertSame(['toma-pedidos'], $report->relatedProducts->pluck('slug')->all());
        $this->assertSame(3, KnowledgeItem::query()->where('title', 'SAP Business One')->sole()->companies()->count());
    }

    public function test_it_is_not_part_of_the_database_seeder(): void
    {
        $this->assertStringNotContainsString('AssistantDemoSeeder', (string) file_get_contents((new \ReflectionClass(DatabaseSeeder::class))->getFileName()));
    }
}
