<?php

namespace Tests\Feature\Mcp;

use App\Models\AiTask;
use App\Models\McpCall;
use App\Models\McpToken;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

/**
 * The unified endpoint `POST /mcp` with personal tokens (`gti_mcp_…`,
 * Profile/Mcp) next to the OAuth tokens of claude.ai: 401 for expired,
 * revoked or unknown tokens, 403 when a read-only token calls a write
 * tool, and every `tools/call` audited in `mcp_calls`.
 */
class PersonalTokenServerTest extends McpToolsTestCase
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callTool(string $value, string $tool, array $arguments = []): TestResponse
    {
        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => (object) $arguments], $value);
    }

    public function test_read_token_lists_only_the_read_tools_and_records_the_client(): void
    {
        ['token' => $token, 'value' => $value] = $this->personalToken($this->jhon, McpToken::SCOPE_READ);

        $this->initialize($value)->assertOk()->assertJsonPath('result.serverInfo.name', 'Gestor IA');

        $token->refresh();
        $this->assertSame('claude-ai', $token->last_client);
        $this->assertNotNull($token->last_used_at);

        $tools = collect($this->rpc('tools/list', [], $value)->assertOk()->json('result.tools'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['quien_soy', 'buscar_solicitudes', 'ver_solicitud'], $tools);
    }

    public function test_read_write_token_lists_every_tool(): void
    {
        ['value' => $value] = $this->personalToken($this->jhon);

        $tools = collect($this->rpc('tools/list', [], $value)->assertOk()->json('result.tools'))->pluck('name')->all();

        $this->assertEqualsCanonicalizing([
            'quien_soy', 'buscar_solicitudes', 'ver_solicitud', 'crear_tarea', 'actualizar_tarea', 'agregar_nota', 'adjuntar_documento',
        ], $tools);
    }

    public function test_read_token_reads_and_the_call_is_audited(): void
    {
        ['token' => $token, 'value' => $value] = $this->personalToken($this->jhon, McpToken::SCOPE_READ);

        $this->callTool($value, 'ver_solicitud', ['clave' => 'gia-139'])->assertOk()->assertJsonPath('result.isError', false);
        $this->callTool($value, 'buscar_solicitudes', ['texto' => 'cartera'])->assertOk()->assertJsonPath('result.isError', false);

        $calls = McpCall::query()->orderBy('id')->get();
        $this->assertCount(2, $calls);
        $this->assertSame([$this->jhon->id, $token->id, null, 'ver_solicitud', 'GIA-139', McpCall::RESULT_OK, null], [
            $calls[0]->user_id, $calls[0]->mcp_token_id, $calls[0]->oauth_client_id, $calls[0]->tool, $calls[0]->target, $calls[0]->result, $calls[0]->message,
        ]);
        $this->assertSame(['buscar_solicitudes', '“cartera”', McpCall::RESULT_OK, '2 resultados'], [
            $calls[1]->tool, $calls[1]->target, $calls[1]->result, $calls[1]->message,
        ]);
    }

    public function test_read_token_calling_a_write_tool_is_forbidden_and_logged_as_rejected(): void
    {
        ['token' => $token, 'value' => $value] = $this->personalToken($this->jhon, McpToken::SCOPE_READ);
        $tasks = AiTask::query()->count();

        $this->callTool($value, 'crear_tarea', ['proyecto' => $this->project->key, 'titulo' => 'No debería existir'])
            ->assertForbidden()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('error.code', -32001)
            ->assertJsonPath('error.message', 'Este token es de solo lectura: no puede usar crear_tarea.');

        $this->assertSame($tasks, AiTask::query()->count());
        $call = McpCall::query()->sole();
        $this->assertSame([$token->id, 'crear_tarea', $this->project->key, McpCall::RESULT_REJECTED, 'token de solo lectura'], [
            $call->mcp_token_id, $call->tool, $call->target, $call->result, $call->message,
        ]);
    }

    public function test_read_write_token_writes_and_the_target_names_the_task(): void
    {
        ['token' => $token, 'value' => $value] = $this->personalToken($this->jhon);

        $this->callTool($value, 'agregar_nota', ['proyecto' => $this->project->key, 'tarea' => $this->t1->key, 'nota' => 'Avance desde Claude Code.'])
            ->assertOk()->assertJsonPath('result.isError', false);

        $call = McpCall::query()->sole();
        $this->assertSame([$token->id, 'agregar_nota', "{$this->project->key} · {$this->t1->key}", McpCall::RESULT_OK], [
            $call->mcp_token_id, $call->tool, $call->target, $call->result,
        ]);
        $this->assertSame('api', Activity::query()->where('event', 'mcp_progress_note')->sole()->getExtraProperty('origin'));
    }

    public function test_tool_errors_are_audited_as_errors(): void
    {
        ['value' => $value] = $this->personalToken($this->jhon);

        $this->callTool($value, 'ver_solicitud', ['clave' => 'GIA-999'])->assertOk()->assertJsonPath('result.isError', true);

        $call = McpCall::query()->sole();
        $this->assertSame([McpCall::RESULT_ERROR, 'GIA-999', 'No encontré la solicitud GIA-999.'], [$call->result, $call->target, $call->message]);
    }

    public function test_expired_revoked_and_unknown_tokens_answer_401(): void
    {
        ['token' => $expired, 'value' => $expiredValue] = $this->personalToken($this->jhon);
        $expired->forceFill(['expires_at' => now('America/Bogota')->subDay()->toDateString()])->save();
        ['token' => $revoked, 'value' => $revokedValue] = $this->personalToken($this->jhon);
        $revoked->forceFill(['revoked_at' => now(), 'revoked_by' => $this->jhon->id])->save();

        foreach ([$expiredValue, $revokedValue, McpToken::PREFIX.str_repeat('A', 40)] as $value) {
            $response = $this->callTool($value, 'ver_solicitud', ['clave' => 'GIA-139']);
            $response->assertUnauthorized();
            $this->assertStringStartsWith('Bearer realm="mcp"', (string) $response->headers->get('WWW-Authenticate'));
        }

        $calls = McpCall::query()->orderBy('id')->get();
        $this->assertCount(2, $calls);
        $this->assertSame([$expired->id, McpCall::RESULT_UNAUTHORIZED, 'token vencido', 'ver_solicitud'], [$calls[0]->mcp_token_id, $calls[0]->result, $calls[0]->message, $calls[0]->tool]);
        $this->assertSame([$revoked->id, McpCall::RESULT_UNAUTHORIZED, 'token revocado'], [$calls[1]->mcp_token_id, $calls[1]->result, $calls[1]->message]);
        $this->assertNull($expired->fresh()->last_used_at);
    }

    public function test_token_still_valid_on_its_last_day(): void
    {
        ['token' => $token, 'value' => $value] = $this->personalToken($this->jhon);
        $token->forceFill(['expires_at' => now('America/Bogota')->toDateString()])->save();

        $this->initialize($value)->assertOk();
    }

    public function test_token_of_a_user_without_the_permission_is_forbidden(): void
    {
        ['value' => $value] = $this->personalToken($this->jhon, McpToken::SCOPE_READ);
        $this->jhon->syncRoles(['Usuario final']);

        $this->callTool($value, 'ver_solicitud', ['clave' => 'GIA-139'])
            ->assertForbidden()
            ->assertJsonPath('error.message', 'No tienes permiso para usar el conector del Gestor IA.');

        $this->assertSame(McpCall::RESULT_REJECTED, McpCall::query()->sole()->result);
    }

    public function test_inactive_user_token_is_refused(): void
    {
        ['value' => $value] = $this->personalToken($this->jhon);
        $this->jhon->forceFill(['active' => false])->save();

        $this->initialize($value)->assertForbidden();
    }

    public function test_oauth_tokens_still_work_on_the_unified_endpoint_and_are_audited(): void
    {
        $token = $this->obtainToken($this->jhon);

        $this->rpc('tools/call', ['name' => 'actualizar_tarea', 'arguments' => [
            'proyecto' => $this->project->key, 'tarea' => $this->t2->key, 'titulo' => 'Endpoint de zonas y asesores',
        ]], $token['access_token'])->assertOk()->assertJsonPath('result.isError', false);

        $call = McpCall::query()->sole();
        $this->assertSame([$this->jhon->id, null, $token['client_id'], 'actualizar_tarea', McpCall::RESULT_OK], [
            $call->user_id, $call->mcp_token_id, $call->oauth_client_id, $call->tool, $call->result,
        ]);
    }

    public function test_the_token_value_never_reaches_logs_audit_or_calls(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        ['value' => $value] = $this->personalToken($this->jhon, McpToken::SCOPE_READ);
        $this->initialize($value)->assertOk();
        $this->callTool($value, 'buscar_solicitudes', ['texto' => $value])->assertOk();
        $this->callTool($value, 'crear_tarea', ['proyecto' => $this->project->key, 'titulo' => 'x'])->assertForbidden();

        foreach ([implode("\n", $logged), Activity::all()->toJson(), McpCall::all()->toJson(), McpToken::all()->toJson()] as $stored) {
            $this->assertStringNotContainsString($value, $stored);
            $this->assertStringNotContainsString(McpToken::PREFIX, $stored);
        }
    }

    public function test_calls_are_rate_limited_per_token(): void
    {
        config(['gestor.mcp.rate_limit_per_minute' => 2]);
        ['value' => $value] = $this->personalToken($this->jhon);

        $this->rpc('tools/list', [], $value)->assertOk();
        $this->rpc('tools/list', [], $value)->assertOk();
        $this->rpc('tools/list', [], $value)->assertStatus(429);
    }

    public function test_disabled_server_answers_404(): void
    {
        config(['gestor.mcp.enabled' => false]);
        ['value' => $value] = $this->personalToken($this->jhon);

        $this->initialize($value)->assertNotFound();
        $this->initialize()->assertNotFound();
    }

    public function test_calls_older_than_the_retention_are_pruned(): void
    {
        config(['gestor.mcp.calls_retention_days' => 180]);
        ['token' => $token] = $this->personalToken($this->jhon);
        $row = ['user_id' => $this->jhon->id, 'mcp_token_id' => $token->id, 'tool' => 'ver_solicitud', 'result' => McpCall::RESULT_OK];

        $this->travelTo(now()->subDays(181));
        $old = McpCall::query()->create($row);
        $this->travelBack();
        $recent = McpCall::query()->create($row);

        $this->artisan('mcp:prune-calls')->assertSuccessful();

        $this->assertSame([$recent->id], McpCall::query()->pluck('id')->all());
        $this->assertFalse(McpCall::query()->whereKey($old->id)->exists());
        $this->assertTrue($token->fresh() !== null);
    }

    public function test_old_path_is_gone(): void
    {
        $this->postJson('/mcp/gestor-ia', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertNotFound();
    }
}
