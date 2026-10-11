<?php

namespace Tests\Feature\Ai\Costs;

use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiProjectCost;
use App\Models\AiTask;
use App\Models\AiTimer;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "Tiempo y costos" (`Ai/Projects/Costs`) and the project costs
 * (`ai.costs.store|update|destroy`, receipts on the private disk).
 */
class ProjectCostsTest extends CostsTestCase
{
    private AiProject $p;

    private AiTask $t1;

    private AiProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = AiProduct::factory()->create();
        $this->p = $this->activeProject(10, 1_000_000, '2026-10-31', ['product_id' => $this->product->id]);
        $this->t1 = $this->task($this->p, 'in_progress', ['title' => 'Endpoint de zonas', 'estimate_hours' => 5]);
        $this->task($this->p, 'done', ['title' => 'Hecha', 'estimate_hours' => 3]);
        $this->rate($this->jhon, 100_000);
        $this->rate($this->superAdmin, 50_000);
    }

    private function seedData(): AiProject
    {
        $this->hours($this->p, $this->jhon, 1, null, '2026-10-01');
        $this->hours($this->p, $this->jhon, 4, $this->t1, '2026-10-06');
        $this->hours($this->p, $this->superAdmin, 2, null, '2026-10-07');

        $other = $this->activeProject();
        $this->hours($other, $this->superAdmin, 1, null, '2026-10-05');

        AiProjectCost::create([
            'ai_project_id' => $this->p->id,
            'type' => 'license',
            'concept' => 'Componente de tablas',
            'recurrence' => 'once',
            'amount' => 380_000,
            'date' => '2026-09-15',
        ]);

        $this->interaction($this->p, 2, '2026-10-02 10:00');
        $this->interaction($this->product, 1.5, '2026-10-03 10:00');
        $this->interaction($this->product, 1, '2026-09-20 10:00');
        $this->interaction($other, 9, '2026-10-02 10:00');

        AiTimer::create([
            'user_id' => $this->superAdmin->id,
            'ai_project_id' => $this->p->id,
            'ai_task_id' => $this->t1->id,
            'started_at' => CarbonImmutable::parse('2026-10-07 09:30', 'America/Bogota'),
        ]);

        return $other;
    }

    public function test_page_returns_exactly_the_contract_props(): void
    {
        $this->assertSame($this->withShared([
            'project', 'budget', 'curve', 'remaining_hours', 'recent_hours', 'recent_weeks', 'pace_note', 'week', 'entries',
            'total_by_person', 'running_timers', 'costs', 'tasks', 'telegram_example', 'today', 'now',
        ]), $this->propKeys(route('ai.projects.costs', $this->p)));

        $this->actingAs($this->paola)->get(route('ai.projects.costs', $this->p))->assertForbidden();
    }

    public function test_page_shows_estimate_vs_real_hours_log_and_costs(): void
    {
        $other = $this->seedData();
        $request = $this->originRequest($this->p);

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.costs', $this->p))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Projects/Costs', false)
                ->where('project.key', $this->p->key)
                ->where('budget.hours', ['estimate' => 10, 'actual' => 7])
                ->where('budget.cost', ['estimate' => 1_000_000, 'actual' => 998_000])
                ->where('budget.due_date', '2026-10-31')
                ->where('budget.projected_due_date', '2026-10-17')
                ->where('budget.approved_at', '2026-09-30')
                ->where('budget.needs_reapproval', false)
                ->where('curve', [
                    'plan' => [['date' => '2026-09-30', 'value' => 0], ['date' => '2026-10-31', 'value' => 10]],
                    'actual' => [
                        ['date' => '2026-09-30', 'value' => 0],
                        ['date' => '2026-10-01', 'value' => 1],
                        ['date' => '2026-10-06', 'value' => 5],
                        ['date' => '2026-10-07', 'value' => 7],
                    ],
                    'projection' => [['date' => '2026-10-07', 'value' => 7], ['date' => '2026-10-17', 'value' => 12]],
                ])
                ->where('remaining_hours', 5)
                ->where('recent_hours', 7)
                ->where('recent_weeks', 2)
                ->where('pace_note', "porque el equipo reparte el tiempo con {$other->key}")
                ->where('week', ['from' => '2026-10-05', 'to' => '2026-10-11'])
                ->has('entries', 2)
                ->where('entries.0.person.name', 'Leonardo Herrera')
                ->where('entries.0.task', null)
                ->where('entries.1', fn ($entry) => $entry['date'] === '2026-10-06'
                    && $entry['project_key'] === $this->p->key
                    && $entry['task'] === ['key' => $this->t1->key, 'title' => 'Endpoint de zonas']
                    && $entry['hours'] === 4
                    && $entry['origin'] === 'manual'
                    && $entry['note'] === 'Trabajo'
                    && is_int($entry['id']))
                ->where('total_by_person.0.person.name', 'Jhon Castro')
                ->where('total_by_person.0.hours', 5)
                ->where('total_by_person.1.hours', 2)
                ->has('running_timers', 1)
                ->where('running_timers.0.person.name', 'Leonardo Herrera')
                ->where('running_timers.0.task_key', $this->t1->key)
                ->has('costs', 4)
                ->where('costs.0', [
                    'id' => -1,
                    'type' => 'team',
                    'concept' => 'Horas del equipo',
                    'detail' => '7 h con el costo por hora interno de cada persona · Jhon Castro 5 h · Leonardo Herrera 2 h',
                    'recurrence' => 'computed',
                    'amount' => 600_000,
                    'date' => '2026-10-07',
                    'attachment' => null,
                    'editable' => false,
                ])
                ->where('costs.1.type', 'license')
                ->where('costs.1.amount', 380_000)
                ->where('costs.1.recurrence', 'once')
                ->where('costs.1.editable', true)
                ->where('costs.2', [
                    'id' => -202610,
                    'type' => 'ai_usage',
                    'concept' => 'Interacciones con IA del proyecto en octubre',
                    'detail' => 'Se suma solo desde el registro de interacciones (ai_interactions); no se edita a mano',
                    'recurrence' => 'monthly',
                    'amount' => 14_000,
                    'date' => '2026-10-01',
                    'attachment' => null,
                    'editable' => false,
                ])
                ->where('costs.3.amount', 4_000)
                ->where('costs.3.date', '2026-09-01')
                ->where('tasks', [['key' => $this->t1->key, 'title' => 'Endpoint de zonas']])
                ->where('telegram_example', "/horas {$request->key} 2 qué hiciste")
                ->where('today', '2026-10-07')
            );
    }

    public function test_week_query_moves_the_log(): void
    {
        $this->seedData();

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.costs', ['project' => $this->p->key, 'week' => '2026-10-01']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('week', ['from' => '2026-09-28', 'to' => '2026-10-04'])
                ->has('entries', 1)
                ->where('entries.0.date', '2026-10-01')
            );
    }

    public function test_without_costs_view_no_money_nor_log_leaves(): void
    {
        $this->seedData();
        $viewer = $this->itWithoutCosts();

        $this->actingAs($viewer)
            ->get(route('ai.projects.costs', $this->p))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('budget.cost', null)
                ->where('entries', [])
                ->where('costs', [])
                ->where('total_by_person', [])
                ->where('running_timers', [])
                ->where('curve', ['plan' => [], 'actual' => [], 'projection' => []])
            );
    }

    public function test_costs_are_added_with_a_private_receipt_edited_and_deleted(): void
    {
        Storage::fake('local');

        $this->actingAs($this->jhon)
            ->post(route('ai.costs.store', $this->p), [
                'type' => 'external_provider',
                'concept' => 'Consultor SAP B1',
                'recurrence' => 'once',
                'amount' => 804_000,
                'date' => '2026-09-22',
                'attachment' => UploadedFile::fake()->create('factura-0193.pdf', 90, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $cost = AiProjectCost::query()->sole();
        $this->assertSame('external_provider', $cost->type);
        $this->assertSame(804_000, $cost->amount);
        $this->assertSame('factura-0193.pdf', $cost->attachment_name);
        Storage::disk('local')->assertExists($cost->attachment_path);

        $url = null;
        $this->actingAs($this->jhon)
            ->get(route('ai.projects.costs', $this->p))
            ->assertInertia(function (Assert $page) use (&$url) {
                $page->where('costs.0.attachment.name', 'factura-0193.pdf');
                $url = $page->toArray()['props']['costs'][0]['attachment']['url'];
            });
        $this->assertStringContainsString('signature=', (string) $url);
        $this->actingAs($this->jhon)->get((string) $url)->assertOk();
        $this->actingAs($this->paola)->get((string) $url)->assertForbidden();

        $this->actingAs($this->jhon)
            ->put(route('ai.costs.update', ['project' => $this->p->key, 'cost' => $cost->id]), [
                'type' => 'hosting', 'concept' => 'Vercel Pro', 'recurrence' => 'monthly', 'amount' => 90_000, 'date' => '2026-10-01',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('monthly', $cost->fresh()->recurrence);
        Storage::disk('local')->assertExists($cost->attachment_path);

        $this->actingAs($this->jhon)->delete(route('ai.costs.destroy', ['project' => $this->p->key, 'cost' => $cost->id]))->assertRedirect();
        $this->assertSame(0, AiProjectCost::query()->count());
        Storage::disk('local')->assertMissing($cost->attachment_path);
    }

    public function test_cost_validation_and_permissions(): void
    {
        $this->actingAs($this->jhon)
            ->post(route('ai.costs.store', $this->p), ['type' => 'ai_usage', 'concept' => '', 'recurrence' => 'computed', 'amount' => null, 'date' => 'x'])
            ->assertSessionHasErrors(['type', 'concept', 'recurrence', 'amount', 'date']);

        $body = ['type' => 'license', 'concept' => 'Licencia', 'recurrence' => 'once', 'amount' => 1000, 'date' => '2026-10-01'];
        $this->actingAs($this->itWithoutCosts())->post(route('ai.costs.store', $this->p), $body)->assertForbidden();
        $this->actingAs($this->paola)->post(route('ai.costs.store', $this->p), $body)->assertForbidden();
        $this->assertSame(0, AiProjectCost::query()->count());
    }

    public function test_export_downloads_the_week_log(): void
    {
        $this->seedData();

        $response = $this->actingAs($this->jhon)
            ->get(route('ai.projects.costs.export', ['project' => $this->p->key, 'week' => '2026-10-05']))
            ->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Fecha;Persona;Tarea;Horas;Origen;Nota', $csv);
        $this->assertStringContainsString('Jhon Castro', $csv);
        $this->assertStringNotContainsString('2026-10-01', $csv);

        $this->actingAs($this->itWithoutCosts())
            ->get(route('ai.projects.costs.export', ['project' => $this->p->key, 'week' => '2026-10-05']))
            ->assertForbidden();
    }
}
