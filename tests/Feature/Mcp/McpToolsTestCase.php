<?php

namespace Tests\Feature\Mcp;

use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\EffortSize;
use App\Enums\Ai\RequestStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AiDocument;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiTask;
use App\Models\Area;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

/**
 * Gestor IA data the E2 tools read and change: GIA-139 (Segurex · Ventas)
 * with its project, two tasks, criteria, comments and a document, and
 * GIA-200 of Derivados (another company).
 */
abstract class McpToolsTestCase extends McpTestCase
{
    protected Company $derivados;

    protected Area $ventas;

    protected Area $cartera;

    protected AiProduct $product;

    protected AiRequest $gia139;

    protected AiRequest $gia200;

    protected AiProject $project;

    protected AiTask $t1;

    protected AiTask $t2;

    protected AiDocument $requirements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segurex->forceFill(['short_name' => 'Segurex'])->save();
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados de Colombia', 'short_name' => 'Derivados', 'slug' => 'derivados']);
        $this->ventas = Area::factory()->create(['company_id' => $this->segurex->id, 'name' => 'Ventas', 'slug' => 'ventas']);
        $this->cartera = Area::factory()->create(['company_id' => $this->derivados->id, 'name' => 'Cartera', 'slug' => 'cartera']);
        $this->product = AiProduct::factory()->create(['name' => 'Visualizador de cartera', 'current_version' => 'v3.1.0']);

        $this->gia139 = AiRequest::factory()->create([
            'key' => 'GIA-139',
            'company_id' => $this->segurex->id,
            'area_id' => $this->ventas->id,
            'requester_id' => $this->paola->id,
            'title' => 'Visualizador de cartera: filtro por asesor y zona',
            'problem' => 'Para ver la cartera de un asesor hay que exportar todo a Excel.',
            'current_process' => 'Cada lunes se arma un Excel por zona.',
            'expected_result' => 'Elegir asesor y zona y ver el total al instante.',
            'status' => RequestStatus::InDevelopment,
            'product_id' => $this->product->id,
            'size' => EffortSize::L,
            'final_priority' => 82,
        ]);
        $this->gia200 = AiRequest::factory()->create([
            'key' => 'GIA-200',
            'company_id' => $this->derivados->id,
            'area_id' => $this->cartera->id,
            'requester_id' => $this->paola->id,
            'title' => 'Reporte de cartera vencida de Derivados',
            'problem' => 'La cartera vencida se calcula a mano.',
            'status' => RequestStatus::Received,
        ]);

        AcceptanceCriterion::factory()->create(['ai_request_id' => $this->gia139->id, 'position' => 1, 'text' => 'Filtrar por asesor.']);
        AcceptanceCriterion::factory()->create(['ai_request_id' => $this->gia139->id, 'position' => 2, 'text' => 'Filtrar por zona.']);
        $this->gia139->comments()->create(['user_id' => $this->paola->id, 'body' => 'comentario-publico-del-solicitante', 'visibility' => CommentVisibility::Public]);

        $this->project = AiProject::factory()->create([
            'company_id' => $this->segurex->id,
            'area_id' => $this->ventas->id,
            'lead_id' => $this->jhon->id,
            'name' => 'Visualizador de cartera · filtros',
            'status' => RequestStatus::InDevelopment,
            'product_id' => $this->product->id,
            'repository_url' => 'https://github.com/GrupoRyR/visualizador-cartera',
        ]);
        $this->project->requests()->attach($this->gia139->id);

        $this->t1 = $this->task('in_progress', [
            'title' => 'Consulta de cartera por asesor',
            'description' => 'Traer de SAP B1 la cartera de cada asesor.',
            'github_pr_number' => 41,
            'github_pr_url' => 'https://github.com/GrupoRyR/visualizador-cartera/pull/41',
        ], [$this->jhon]);
        $this->t2 = $this->task('todo', [
            'title' => 'Endpoint de zonas',
            'github_issue_number' => 57,
            'github_issue_url' => 'https://github.com/GrupoRyR/visualizador-cartera/issues/57',
        ]);

        $this->requirements = $this->document($this->project, 'requirements', 'Requerimientos y criterios', 'project', "# Requerimientos\n\nFiltro por asesor y por zona.");
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<User>  $assignees
     */
    protected function task(string $column, array $attributes = [], array $assignees = []): AiTask
    {
        $task = AiTask::factory()->create([
            'ai_project_id' => $this->project->id,
            'ai_project_column_id' => $this->project->column($column)->id,
            ...$attributes,
        ]);

        if ($assignees !== []) {
            $task->assignees()->sync(collect($assignees)->pluck('id')->all());
        }

        return $task;
    }

    protected function document(AiProject|AiProduct $owner, string $template, string $title, string $visibility, string $body): AiDocument
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
     * A connector user with its own role (`ai.mcp.use` plus `$permissions`).
     *
     * @param  list<string>  $permissions
     */
    protected function connectorUser(string $role, array $permissions, ?Company $company = null): User
    {
        Role::create(['name' => $role, 'guard_name' => 'web'])->givePermissionTo(['ai.mcp.use', ...$permissions]);
        $user = $this->user($role, $role.' Usuario');

        if ($company !== null) {
            $user->forceFill(['company_id' => $company->id])->save();
        }

        return $user;
    }

    /**
     * `tools/call` as `$user` (Jhon by default) with a Passport token.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function tool(string $name, array $arguments = [], ?User $user = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Passport::actingAs($user ?? $this->jhon, ['mcp:use'], 'api');

        return $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
    }

    /**
     * Decoded JSON of a successful tool result.
     *
     * @return array<string, mixed>
     */
    protected function toolResult(TestResponse $response): array
    {
        $response->assertOk()->assertJsonPath('result.isError', false);

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    /**
     * Text of a tool error (`isError: true`, never a JSON-RPC error).
     */
    protected function toolError(TestResponse $response): string
    {
        $response->assertOk()->assertJsonPath('result.isError', true);

        return (string) $response->json('result.content.0.text');
    }
}
