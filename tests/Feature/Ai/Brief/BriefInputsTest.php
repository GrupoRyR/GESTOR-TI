<?php

namespace Tests\Feature\Ai\Brief;

use App\Enums\Ai\AcceptanceResult;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\EffortSize;
use App\Enums\Ai\RequestStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AcceptanceCriterion;
use App\Models\AiCostRate;
use App\Models\AiDocument;
use App\Models\AiEstimate;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiProjectCost;
use App\Models\AiRequest;
use App\Models\AiTask;
use App\Models\AiVaultItem;
use App\Models\GitHubRepository;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Ai\Projects\ProjectsTestCase;

/**
 * "Brief para Claude" (PROMPT 6.4.7 step 1): whitelisted inputs of
 * `RequestBriefInput`, `TaskBriefInput` and `ProjectBriefInput` on the
 * request IT view, the project summary, the board drawer and the task
 * page. Only for `ai.manage`; the requester view never has a brief; the
 * vault, credentials, costs, rates, hours and internal notes never leave.
 */
class BriefInputsTest extends ProjectsTestCase
{
    private const REPO_URL = 'https://github.com/GrupoRyR/visualizador-cartera';

    private AiProduct $product;

    private AiProject $p;

    private AiRequest $gia139;

    private AiTask $t1;

    private AiTask $t2;

    private AiDocument $manual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paola->employee->forceFill(['job_title' => 'Gerencia de cartera'])->save();

        $this->product = AiProduct::factory()->create([
            'name' => 'Visualizador de cartera', 'current_version' => 'v3.1.0', 'stack' => 'Next.js', 'hosting' => 'Vercel',
            'monthly_cost' => 3_456_789,
        ]);
        $repo = GitHubRepository::factory()->create([
            'github_id' => 777, 'full_name' => 'GrupoRyR/visualizador-cartera', 'name' => 'visualizador-cartera',
            'html_url' => self::REPO_URL, 'default_branch' => 'main',
        ]);
        $repo->linkTo($this->product, 'main', $this->jhon);

        $this->p = $this->project([
            'name' => 'Visualizador de cartera · filtros',
            'status' => RequestStatus::InDevelopment,
            'product_id' => $this->product->id,
            'objective' => 'Que la Gerencia de cartera filtre la cartera por asesor y por zona.',
        ]);
        $this->gia139 = $this->request([
            'key' => 'GIA-139',
            'title' => 'Visualizador de cartera: filtro por asesor y zona',
            'status' => RequestStatus::InDevelopment,
            'product_id' => $this->product->id,
            'problem' => 'Para ver la cartera de un asesor hay que exportar todo a Excel.',
            'current_process' => 'Cada lunes se arma un Excel por zona.',
            'expected_result' => 'Elegir asesor y zona y ver el total al instante.',
            'hours_per_week' => 4,
            'people_count' => 5,
            'size' => EffortSize::L,
            'systems' => ['SAP Business One'],
        ]);
        $this->p->requests()->attach($this->gia139->id);

        AcceptanceCriterion::factory()->create([
            'ai_request_id' => $this->gia139->id, 'position' => 1, 'text' => 'Filtrar por asesor.',
            'result' => AcceptanceResult::Pass, 'validated_at' => now(), 'validated_by_id' => $this->paola->id,
        ]);
        AcceptanceCriterion::factory()->create(['ai_request_id' => $this->gia139->id, 'position' => 2, 'text' => 'Filtrar por zona.']);

        $this->t1 = $this->task($this->p, 'in_progress', [
            'title' => 'Consulta de cartera por asesor',
            'description' => 'Traer de SAP B1 la cartera de cada asesor.',
            'github_pr_number' => 41,
            'estimate_hours' => 12.5,
        ], [$this->jhon]);
        $this->t1->subtasks()->create(['title' => 'Conectar a la vista de cartera', 'done' => true, 'position' => 1]);
        $this->t1->subtasks()->create(['title' => 'Agrupar por días vencidos', 'done' => false, 'position' => 2]);
        $this->t2 = $this->task($this->p, 'todo', ['title' => 'Endpoint de zonas', 'github_issue_number' => 57]);

        $this->document($this->p, 'decisions', 'Registro de decisiones', 'project',
            "# Registro de decisiones\n\n| Fecha | Decisión | Motivo |\n|---|---|---|\n| 2026-10-05 | SAP B1 se lee solo por la vista de solo lectura | Seguridad |\n|  |  |  |\n");
        $this->manual = $this->document($this->product, 'technical_manual', 'Manual técnico y de despliegue', 'it', '# Manual');
        $this->document($this->p, 'prompts', 'Prompts y modelos', 'it', '# Prompts · costo estimado US$ 30');
    }

    private function document(AiProject|AiProduct $owner, string $template, string $title, string $visibility, string $body): AiDocument
    {
        return AiDocument::create([
            'ai_project_id' => $owner instanceof AiProject ? $owner->id : null,
            'ai_product_id' => $owner instanceof AiProduct ? $owner->id : null,
            'title' => $title,
            'slug' => str($title)->slug()->toString().'-'.$owner->id,
            'template' => $template,
            'body' => $body,
            'visibility' => $visibility,
            'version' => 1,
            'author_id' => $this->jhon->id,
            'updated_by_id' => $this->jhon->id,
        ]);
    }

    /**
     * Vault items, rates, hours, costs, estimates, internal notes, task
     * comments and an area hour value: none of these may reach a brief.
     */
    private function seedSensitiveData(): void
    {
        config(['services.vault.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $item = new AiVaultItem([
            'name' => 'SAP_VAULT_LOGIN', 'ai_project_id' => $this->p->id, 'environment' => 'production', 'type' => 'user_password',
            'username' => 'sap.vault.user', 'url' => 'https://sap-vault-host.example', 'notes' => 'nota-secreta-de-la-boveda',
        ]);
        $item->setSecret('VaultSecret-XYZ-998877');
        $item->save();

        AiCostRate::create(['user_id' => $this->jhon->id, 'rate' => 187_654, 'effective_from' => '2026-01-01']);
        $this->hours($this->p, $this->jhon, 7.25, $this->t1)->forceFill(['note' => 'nota-de-horas-registradas'])->save();
        AiProjectCost::create([
            'ai_project_id' => $this->p->id, 'type' => 'external_provider', 'concept' => 'Licencia-concepto-costo',
            'recurrence' => 'once', 'amount' => 4_321_987, 'date' => '2026-10-01',
        ]);
        AiEstimate::create(['ai_project_id' => $this->p->id, 'hours_total' => 61, 'external_costs' => 0, 'total_cost' => 9_876_543, 'due_date' => '2026-12-01']);
        AiEstimate::create(['ai_request_id' => $this->gia139->id, 'hours_total' => 62, 'external_costs' => 1_234_321, 'total_cost' => 8_765_432, 'due_date' => '2026-12-01']);
        $this->ventas->forceFill(['hour_value' => 55_555])->save();

        $this->gia139->comments()->create(['user_id' => $this->jhon->id, 'body' => 'Nota interna: el proveedor cobra demasiado', 'visibility' => CommentVisibility::Internal]);
        $this->gia139->comments()->create(['user_id' => $this->jhon->id, 'body' => 'Otra nota interna reservada de TI', 'visibility' => CommentVisibility::Internal]);
        $this->gia139->comments()->create(['user_id' => $this->paola->id, 'body' => 'comentario-publico-del-solicitante', 'visibility' => CommentVisibility::Public]);
        $this->t1->comments()->create(['user_id' => $this->jhon->id, 'body' => 'comentario-de-la-tarea']);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(string $url): array
    {
        return $this->actingAs($this->jhon)->get($url)->assertOk()->viewData('page')['props'];
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function expectedLinks(): array
    {
        return [
            ['label' => 'Repositorio', 'url' => self::REPO_URL],
            ['label' => 'Instrucciones para Claude (CLAUDE.md)', 'url' => self::REPO_URL.'/blob/main/CLAUDE.md'],
            ['label' => 'Manual técnico y de despliegue', 'url' => route('ai.docs.edit', $this->manual->id)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedProduct(): array
    {
        return ['name' => 'Visualizador de cartera', 'version' => 'v3.1.0', 'stack' => 'Next.js', 'host' => 'Vercel', 'data_source' => 'SAP Business One'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expectedTasks(): array
    {
        return [
            ['key' => $this->t2->key, 'title' => 'Endpoint de zonas', 'status' => 'Por hacer', 'github' => 'Issue #57'],
            ['key' => $this->t1->key, 'title' => 'Consulta de cartera por asesor', 'status' => 'En progreso', 'github' => 'PR #41'],
        ];
    }

    public function test_project_summary_sends_the_whitelisted_brief_and_what_was_omitted(): void
    {
        $this->seedSensitiveData();

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.show', $this->p))
            ->assertInertia(fn (Assert $page) => $page
                ->where('brief', [
                    'key' => $this->p->key,
                    'name' => 'Visualizador de cartera · filtros',
                    'company' => $this->segurex->short_name,
                    'area' => 'Ventas',
                    'status' => 'En desarrollo',
                    'phase' => 'Construcción',
                    'request_keys' => ['GIA-139'],
                    'objective' => 'Que la Gerencia de cartera filtre la cartera por asesor y por zona.',
                    'criteria' => [['text' => 'Filtrar por asesor.', 'done' => true], ['text' => 'Filtrar por zona.', 'done' => false]],
                    'product' => $this->expectedProduct(),
                    'links' => $this->expectedLinks(),
                    'tasks' => $this->expectedTasks(),
                    'decisions' => [['text' => 'SAP B1 se lee solo por la vista de solo lectura. Motivo: Seguridad']],
                ])
                ->where('brief_omitted', ['time_and_costs' => true, 'internal_notes' => 2])
                ->where('brief_state', null)
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.show', [$this->p, 'brief' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('brief_state', ['open' => true]));
    }

    public function test_nothing_is_omitted_when_the_project_has_no_time_costs_or_notes(): void
    {
        $this->t1->forceFill(['estimate_hours' => null])->save();

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.show', $this->p))
            ->assertInertia(fn (Assert $page) => $page->where('brief_omitted', ['time_and_costs' => false, 'internal_notes' => 0]));
    }

    public function test_project_without_ai_manage_gets_no_brief(): void
    {
        $this->actingAs($this->ventasApprover)
            ->get(route('ai.projects.show', [$this->p, 'brief' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('brief', null)
                ->where('brief_omitted', null)
                ->where('brief_state', null)
            );
    }

    public function test_request_it_view_sends_the_brief_and_the_requester_view_never_does(): void
    {
        $this->seedSensitiveData();

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.show', [$this->gia139, 'brief' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'it')
                ->where('brief', [
                    'key' => 'GIA-139',
                    'title' => 'Visualizador de cartera: filtro por asesor y zona',
                    'company' => $this->segurex->short_name,
                    'area' => 'Ventas',
                    'requester' => 'Gerencia de cartera',
                    'size' => 'L',
                    'project_key' => $this->p->key,
                    'problem' => 'Para ver la cartera de un asesor hay que exportar todo a Excel.',
                    'current_process' => 'Cada lunes se arma un Excel por zona.',
                    'expected_result' => 'Elegir asesor y zona y ver el total al instante.',
                    'hours_per_week' => 4,
                    'people' => 5,
                    'criteria' => ['Filtrar por asesor.', 'Filtrar por zona.'],
                    'product' => $this->expectedProduct(),
                    'links' => $this->expectedLinks(),
                    'tasks' => $this->expectedTasks(),
                    'decisions' => [['text' => 'SAP B1 se lee solo por la vista de solo lectura. Motivo: Seguridad']],
                ])
                ->where('brief_state', ['open' => true])
            );

        $this->actingAs($this->paola)
            ->get(route('ai.requests.show', [$this->gia139, 'brief' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'requester')
                ->missing('brief')
                ->missing('brief_state')
            );
    }

    public function test_task_page_sends_the_task_brief_only_with_ai_manage(): void
    {
        $this->seedSensitiveData();
        $expected = $this->expectedTaskBrief();

        $this->actingAs($this->jhon)
            ->get(route('ai.tasks.show', [$this->p, $this->t1]))
            ->assertInertia(fn (Assert $page) => $page->where('brief', $expected)->where('brief_state', null));

        $this->actingAs($this->jhon)
            ->get(route('ai.tasks.show', [$this->p, $this->t1, 'brief' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('brief_state', ['open' => true]));

        $this->actingAs($this->ventasApprover)
            ->get(route('ai.tasks.show', [$this->p, $this->t1, 'brief' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('brief', null)->where('brief_state', null));
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedTaskBrief(): array
    {
        return [
            'key' => $this->t1->key,
            'title' => 'Consulta de cartera por asesor',
            'project' => ['key' => $this->p->key, 'name' => 'Visualizador de cartera · filtros'],
            'request' => ['key' => 'GIA-139', 'title' => 'Visualizador de cartera: filtro por asesor y zona'],
            'status' => 'En progreso',
            'assignees' => ['Jhon Castro'],
            'description' => 'Traer de SAP B1 la cartera de cada asesor.',
            'subtasks' => [['title' => 'Conectar a la vista de cartera', 'done' => true], ['title' => 'Agrupar por días vencidos', 'done' => false]],
            'criteria' => ['Filtrar por asesor.', 'Filtrar por zona.'],
            'product' => $this->expectedProduct(),
            'links' => $this->expectedLinks(),
            'pull_request' => null,
            'issue' => null,
            'suggested_branch' => null,
            'tasks' => $this->expectedTasks(),
            'decisions' => [['text' => 'SAP B1 se lee solo por la vista de solo lectura. Motivo: Seguridad']],
        ];
    }

    public function test_board_sends_the_drawer_task_brief_lazily_with_its_state(): void
    {
        $expected = $this->expectedTaskBrief();

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', $this->p))
            ->assertInertia(fn (Assert $page) => $page->where('selected_task_brief', null)->where('selected_task_brief_state', null));

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', [$this->p, 'tarea' => $this->t1->key, 'brief' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected_task_brief', $expected)
                ->where('selected_task_brief_state', ['open' => true])
            );

        // The drawer reloads with `only: ['selected_task', 'selected_task_github', 'selected_task_brief']`.
        $this->actingAs($this->jhon)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Ai/Projects/Board',
                'X-Inertia-Partial-Data' => 'selected_task,selected_task_github,selected_task_brief',
            ])
            ->get(route('ai.projects.board', [$this->p, 'tarea' => $this->t2->key]))
            ->assertOk()
            ->assertJsonPath('props.selected_task.key', $this->t2->key)
            ->assertJsonPath('props.selected_task_brief.key', $this->t2->key)
            ->assertJsonPath('props.selected_task_brief.issue', ['number' => 57])
            ->assertJsonMissingPath('props.tasks');
        $this->flushHeaders();

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', [$this->p, 'tarea' => 'T99']))
            ->assertInertia(fn (Assert $page) => $page->where('selected_task_brief', null));

        $this->actingAs($this->ventasApprover)
            ->get(route('ai.projects.board', [$this->p, 'tarea' => $this->t1->key, 'brief' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('selected_task_brief', null)->where('selected_task_brief_state', null));
    }

    public function test_no_vault_credential_cost_rate_hour_or_internal_note_reaches_any_brief(): void
    {
        $this->seedSensitiveData();

        $briefs = [
            ...array_intersect_key($this->props(route('ai.projects.show', $this->p)), array_flip(['brief', 'brief_omitted'])),
            'request' => $this->props(route('ai.requests.show', $this->gia139))['brief'],
            'task' => $this->props(route('ai.tasks.show', [$this->p, $this->t1]))['brief'],
            'board' => $this->props(route('ai.projects.board', [$this->p, 'tarea' => $this->t1->key]))['selected_task_brief'],
        ];
        $this->assertNotNull($briefs['brief']);
        $this->assertNotNull($briefs['request']);
        $this->assertNotNull($briefs['task']);
        $this->assertNotNull($briefs['board']);

        $json = json_encode($briefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ([
            'SAP_VAULT_LOGIN', 'sap.vault.user', 'sap-vault-host', 'nota-secreta-de-la-boveda', 'VaultSecret-XYZ-998877',
            '187654', '187.654', '4321987', '4.321.987', 'Licencia-concepto-costo', '9876543', '8765432', '1234321', '3456789',
            '55555', '7.25', '12.5', 'nota-de-horas-registradas', 'Nota interna', 'reservada de TI', 'comentario-publico-del-solicitante',
            'comentario-de-la-tarea', 'Prompts y modelos', 'US$ 30', 'paola', 'Paola',
        ] as $value) {
            $this->assertStringNotContainsString($value, (string) $json, "The brief leaked «{$value}».");
        }
    }

    public function test_text_with_credentials_money_internal_notes_or_vault_links_is_dropped(): void
    {
        $this->gia139->comments()->create(['user_id' => $this->jhon->id, 'body' => 'El cliente pidió no tocar la zona norte', 'visibility' => CommentVisibility::Internal]);
        $this->t1->forceFill(['description' => 'Conectar con password=Sup3rS3creta! en pruebas.'])->save();
        $this->t1->subtasks()->create(['title' => 'Usar api_key=abcdefgh12345678 del proveedor', 'done' => false, 'position' => 3]);
        $this->t1->subtasks()->create(['title' => 'Revisar: el cliente pidió no tocar la zona norte', 'done' => false, 'position' => 4]);
        $this->gia139->forceFill(['current_process' => 'Hoy cuesta $ 2.500.000 al mes armarlo.'])->save();
        $this->document($this->product, 'architecture', 'Accesos en la bóveda', 'it', '# Accesos');
        AiDocument::query()->where('template', 'decisions')->update([
            'body' => "| Fecha | Decisión | Motivo |\n|---|---|---|\n| 1 | Se paga USD 300 al proveedor | Costo |\n| 2 | Zonas de la lista de Ventas | Ventas |\n",
        ]);

        $task = $this->props(route('ai.tasks.show', [$this->p, $this->t1]))['brief'];
        $this->assertSame('', $task['description']);
        $this->assertSame(['Conectar a la vista de cartera', 'Agrupar por días vencidos'], array_column($task['subtasks'], 'title'));
        $this->assertSame([['text' => 'Zonas de la lista de Ventas. Motivo: Ventas']], $task['decisions']);
        $this->assertNotContains('Accesos en la bóveda', array_column($task['links'], 'label'));

        $request = $this->props(route('ai.requests.show', $this->gia139))['brief'];
        $this->assertNull($request['current_process']);
        $this->assertSame('Para ver la cartera de un asesor hay que exportar todo a Excel.', $request['problem']);

        $project = $this->props(route('ai.projects.show', $this->p));
        $this->assertSame(['time_and_costs' => true, 'internal_notes' => 1], $project['brief_omitted']);
    }
}
