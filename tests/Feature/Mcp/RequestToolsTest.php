<?php

namespace Tests\Feature\Mcp;

use App\Enums\Ai\CommentVisibility;
use App\Mcp\Support\SafeText;
use App\Models\AiCostRate;
use App\Models\AiEstimate;
use App\Models\AiTimeEntry;
use App\Models\AiVaultItem;
use Laravel\Passport\Passport;

/**
 * `buscar_solicitudes` and `ver_solicitud`: what the user sees in the app,
 * in their company scope, and never vault items, rates or hidden notes.
 */
class RequestToolsTest extends McpToolsTestCase
{
    public function test_tools_list_shows_every_tool_with_its_annotations(): void
    {
        $this->app['auth']->forgetGuards();
        Passport::actingAs($this->jhon, ['mcp:use'], 'api');

        $tools = collect($this->rpc('tools/list')->assertOk()->json('result.tools'))->keyBy('name');

        $this->assertEqualsCanonicalizing([
            'quien_soy', 'buscar_solicitudes', 'ver_solicitud', 'crear_tarea', 'actualizar_tarea',
            'agregar_nota', 'adjuntar_documento',
        ], $tools->keys()->all());

        foreach (['quien_soy', 'buscar_solicitudes', 'ver_solicitud'] as $read) {
            $this->assertTrue($tools[$read]['annotations']['readOnlyHint'] ?? false, $read);
        }

        foreach (['crear_tarea', 'actualizar_tarea', 'agregar_nota', 'adjuntar_documento'] as $write) {
            $this->assertFalse($tools[$write]['annotations']['readOnlyHint'] ?? false, $write);
            $this->assertFalse($tools[$write]['annotations']['destructiveHint'] ?? true, $write);
        }

        $this->assertSame(['clave'], $tools['ver_solicitud']['inputSchema']['required']);
    }

    public function test_buscar_solicitudes_finds_by_text_with_the_whitelisted_fields(): void
    {
        $result = $this->toolResult($this->tool('buscar_solicitudes', ['texto' => 'cartera asesor']));

        $this->assertSame(1, $result['total']);
        $row = $result['solicitudes'][0];
        $this->assertSame([
            'clave' => 'GIA-139',
            'titulo' => 'Visualizador de cartera: filtro por asesor y zona',
            'estado' => 'En desarrollo',
            'estado_clave' => 'in_development',
            'producto' => 'Visualizador de cartera',
            'empresa' => 'Segurex',
            'area' => 'Ventas',
            'prioridad' => 82,
            'actualizada' => $this->gia139->fresh()->updated_at->toIso8601String(),
            'url' => route('ai.requests.show', 'GIA-139'),
        ], $row);
    }

    public function test_tools_answer_with_a_real_oauth_token(): void
    {
        $token = $this->obtainToken($this->jhon);

        $response = $this->rpc('tools/call', ['name' => 'ver_solicitud', 'arguments' => ['clave' => 'GIA-139']], $token['access_token']);

        $this->assertSame('GIA-139', $this->toolResult($response)['clave']);
    }

    public function test_buscar_solicitudes_filters_by_status_company_area_and_product(): void
    {
        $keys = fn (array $arguments) => array_column($this->toolResult($this->tool('buscar_solicitudes', $arguments))['solicitudes'], 'clave');

        $this->assertEqualsCanonicalizing(['GIA-139', 'GIA-200'], $keys([]));
        $this->assertSame(['GIA-200'], $keys(['estado' => 'received']));
        $this->assertSame(['GIA-200'], $keys(['empresa' => 'derivados']));
        $this->assertSame(['GIA-139'], $keys(['area' => 'Ventas']));
        $this->assertSame(['GIA-139'], $keys(['producto' => 'visualizador']));
        $this->assertSame(['GIA-139'], $keys(['texto' => 'gia-139']));
    }

    public function test_buscar_solicitudes_validates_its_input_in_spanish(): void
    {
        $this->assertStringContainsString('25', $this->toolError($this->tool('buscar_solicitudes', ['limite' => 80])));
        $this->assertStringContainsString('estado', $this->toolError($this->tool('buscar_solicitudes', ['estado' => 'volando'])));
    }

    public function test_buscar_solicitudes_respects_the_company_scope(): void
    {
        $restricted = $this->connectorUser('TI Segurex', ['ai.manage']);

        $result = $this->toolResult($this->tool('buscar_solicitudes', ['texto' => 'cartera'], $restricted));

        $this->assertSame(['GIA-139'], array_column($result['solicitudes'], 'clave'));
        $this->assertSame([], $this->toolResult($this->tool('buscar_solicitudes', ['empresa' => 'derivados'], $restricted))['solicitudes']);
    }

    public function test_buscar_solicitudes_only_lists_requests_the_user_may_open(): void
    {
        // `ai.mcp.use` without `ai.manage` nor `ai.requests.create`: sees nothing.
        $outsider = $this->connectorUser('Conector sin Gestor', ['companies.view_all']);

        $this->assertSame([], $this->toolResult($this->tool('buscar_solicitudes', [], $outsider))['solicitudes']);
    }

    public function test_ver_solicitud_returns_the_request_with_its_project_tasks_and_documents(): void
    {
        $result = $this->toolResult($this->tool('ver_solicitud', ['clave' => 'gia-139']));

        $this->assertSame('GIA-139', $result['clave']);
        $this->assertSame(route('ai.requests.show', 'GIA-139'), $result['url']);
        $this->assertSame('En desarrollo', $result['estado']);
        $this->assertSame('Segurex', $result['empresa']);
        $this->assertSame('Ventas', $result['area']);
        $this->assertSame('Para ver la cartera de un asesor hay que exportar todo a Excel.', $result['problema']);
        $this->assertSame('Cada lunes se arma un Excel por zona.', $result['proceso_actual']);
        $this->assertSame('Elegir asesor y zona y ver el total al instante.', $result['resultado_esperado']);
        $this->assertSame(['Filtrar por asesor.', 'Filtrar por zona.'], array_column($result['criterios_aceptacion'], 'texto'));
        $this->assertSame('L', $result['evaluacion']['tamano']);
        $this->assertSame(82, $result['evaluacion']['prioridad_final']);
        $this->assertSame(['comentario-publico-del-solicitante'], array_column($result['comentarios'], 'texto'));

        $project = $result['proyectos'][0];
        $this->assertSame($this->project->key, $project['clave']);
        $this->assertSame('https://github.com/GrupoRyR/visualizador-cartera', $project['repositorio']);
        $tasks = collect($project['tareas'])->keyBy('clave');
        $this->assertSame('En progreso', $tasks[$this->t1->key]['columna']);
        $this->assertSame(['Jhon Castro'], $tasks[$this->t1->key]['responsables']);
        $this->assertSame('https://github.com/GrupoRyR/visualizador-cartera/pull/41', $tasks[$this->t1->key]['pull_request']);
        $this->assertSame('https://github.com/GrupoRyR/visualizador-cartera/issues/57', $tasks[$this->t2->key]['issue']);
        $this->assertSame(route('ai.tasks.show', [$this->project->key, $this->t2->key]), $tasks[$this->t2->key]['url']);

        $document = $result['documentos'][0];
        $this->assertSame($this->requirements->id, $document['id']);
        $this->assertSame('Requerimientos y criterios', $document['titulo']);
        $this->assertStringContainsString('Filtro por asesor y por zona.', $document['contenido']);
        $this->assertSame(route('ai.docs.edit', $this->requirements->id), $document['url']);
    }

    public function test_ver_solicitud_refuses_unknown_and_out_of_scope_keys(): void
    {
        $restricted = $this->connectorUser('TI Segurex', ['ai.manage']);

        $this->assertSame('No encontré la solicitud GIA-999.', $this->toolError($this->tool('ver_solicitud', ['clave' => 'GIA-999'])));
        $this->assertSame('No encontré la solicitud GIA-200.', $this->toolError($this->tool('ver_solicitud', ['clave' => 'GIA-200'], $restricted)));
        $this->assertStringContainsString('GIA-', $this->toolError($this->tool('ver_solicitud', ['clave' => 'hola'])));
    }

    public function test_internal_notes_follow_the_app_permissions_and_costs_never_leave(): void
    {
        $this->seedSensitiveData();
        $this->assertTrue($this->jhon->can('ai.costs.view'));
        $this->gia139->forceFill(['expected_result' => 'Ahorrar $ 4.500.000 al mes en horas de cartera.'])->save();

        $jhon = $this->toolResult($this->tool('ver_solicitud', ['clave' => 'GIA-139']));
        $this->assertSame(['Nota interna: el proveedor cobra demasiado'], array_column($jhon['notas_internas'], 'texto'));
        // User rule (Stage E): never costs, even with `ai.costs.view`.
        $this->assertArrayNotHasKey('costos', $jhon);
        $this->assertSame(SafeText::OMITTED, $jhon['resultado_esperado']);
        $raw = json_encode($jhon, JSON_UNESCAPED_UNICODE);
        foreach (['8765432', '8.765.432', '8_765_432', '1234321', '1.234.321', '4.500.000', 'total_cost', 'external_costs', 'impacto_mensual'] as $cost) {
            $this->assertStringNotContainsString($cost, $raw, $cost);
        }

        $withoutCosts = $this->connectorUser('TI sin costos', ['ai.manage', 'companies.view_all']);
        $result = $this->toolResult($this->tool('ver_solicitud', ['clave' => 'GIA-139'], $withoutCosts));
        $this->assertArrayNotHasKey('costos', $result);
        $this->assertArrayHasKey('notas_internas', $result);

        $requesterSide = $this->connectorUser('Conector solicitante', ['ai.requests.create']);
        $result = $this->toolResult($this->tool('ver_solicitud', ['clave' => 'GIA-139'], $requesterSide));
        $this->assertArrayNotHasKey('notas_internas', $result);
        $this->assertSame([], $result['documentos']);
        $raw = json_encode($result, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('proveedor cobra demasiado', $raw);
    }

    public function test_vault_items_rates_hours_and_credentials_never_leave_through_the_connector(): void
    {
        $this->seedSensitiveData();
        $this->gia139->forceFill(['current_process' => 'Se entra a SAP con password=Cartera2026! y se exporta.'])->save();
        $this->t2->forceFill(['description' => 'Usar api_key = sk-livekey1234567890abcdef'])->save();

        $responses = [
            $this->tool('ver_solicitud', ['clave' => 'GIA-139'])->getContent(),
            $this->tool('buscar_solicitudes', ['texto' => 'cartera'])->getContent(),
            $this->tool('ver_solicitud', ['clave' => 'GIA-139'], $this->connectorUser('TI sin costos', ['ai.manage', 'companies.view_all']))->getContent(),
        ];

        foreach ($responses as $raw) {
            foreach ([
                'SAP_VAULT_LOGIN', 'sap.vault.user', 'sap-vault-host', 'nota-secreta-de-la-boveda', 'VaultSecret',
                '187654', '187_654', 'nota-de-horas-registradas', 'Cartera2026', 'sk-livekey',
            ] as $secret) {
                $this->assertStringNotContainsString($secret, $raw, $secret);
            }
        }

        $result = $this->toolResult($this->tool('ver_solicitud', ['clave' => 'GIA-139']));
        $this->assertStringContainsString('credencial', $result['proceso_actual']);
    }

    private function seedSensitiveData(): void
    {
        config(['services.vault.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $item = new AiVaultItem([
            'name' => 'SAP_VAULT_LOGIN', 'ai_project_id' => $this->project->id, 'environment' => 'production', 'type' => 'user_password',
            'username' => 'sap.vault.user', 'url' => 'https://sap-vault-host.example', 'notes' => 'nota-secreta-de-la-boveda',
        ]);
        $item->setSecret('VaultSecret-XYZ-998877');
        $item->save();

        AiCostRate::create(['user_id' => $this->jhon->id, 'rate' => 187_654, 'effective_from' => '2026-01-01']);
        AiTimeEntry::create([
            'user_id' => $this->jhon->id, 'ai_project_id' => $this->project->id, 'ai_task_id' => $this->t1->id,
            'date' => now()->toDateString(), 'hours' => 7.25, 'origin' => 'manual', 'note' => 'nota-de-horas-registradas',
        ]);
        AiEstimate::create(['ai_request_id' => $this->gia139->id, 'hours_total' => 62, 'external_costs' => 1_234_321, 'total_cost' => 8_765_432, 'due_date' => '2026-12-01']);

        $this->gia139->comments()->create(['user_id' => $this->jhon->id, 'body' => 'Nota interna: el proveedor cobra demasiado', 'visibility' => CommentVisibility::Internal]);
    }
}
