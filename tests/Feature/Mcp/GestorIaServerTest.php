<?php

namespace Tests\Feature\Mcp;

use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/**
 * The Gestor IA MCP server over plain JSON per POST: identity end to end
 * (`quien_soy`) and per-request access (`ai.mcp.use`, active user, token).
 */
class GestorIaServerTest extends McpTestCase
{
    public function test_initialize_describes_the_server_in_spanish(): void
    {
        Passport::actingAs($this->jhon, ['mcp:use'], 'api');

        $response = $this->initialize();

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('result.serverInfo.name', 'Gestor IA')
            ->assertJsonPath('result.protocolVersion', '2025-06-18');

        $this->assertStringContainsString('Gestor IA', $response->json('result.instructions'));
    }

    public function test_tools_list_exposes_quien_soy(): void
    {
        Passport::actingAs($this->jhon, ['mcp:use'], 'api');

        $tools = collect($this->rpc('tools/list')->assertOk()->json('result.tools'));

        $this->assertSame(['quien_soy'], $tools->pluck('name')->all());
        $this->assertTrue($tools->first()['annotations']['readOnlyHint'] ?? false);
    }

    public function test_quien_soy_returns_the_token_owner(): void
    {
        $token = $this->obtainToken($this->jhon);

        $response = $this->rpc('tools/call', ['name' => 'quien_soy', 'arguments' => (object) []], $token['access_token']);

        $response->assertOk()->assertJsonPath('result.isError', false);
        $identity = json_decode($response->json('result.content.0.text'), true);

        $this->assertSame([
            'nombre' => 'Jhon Castro',
            'correo' => $this->jhon->email,
            'roles' => ['Líder IA'],
            'empresa' => ['id' => $this->segurex->id, 'nombre' => 'Segurex Latam'],
            // Líder IA holds companies.view_all.
            've_todas_las_empresas' => true,
        ], $identity);
    }

    public function test_user_without_permission_is_refused_on_every_request(): void
    {
        Passport::actingAs($this->paola, ['mcp:use'], 'api');

        $this->initialize()
            ->assertForbidden()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('id', 1)
            ->assertJsonPath('error.message', 'No tienes permiso para usar el conector del Gestor IA.');
    }

    public function test_permission_removed_after_consent_closes_access(): void
    {
        $token = $this->obtainToken($this->jhon);
        $this->jhon->syncRoles(['Usuario final']);

        $this->rpc('tools/list', [], $token['access_token'])->assertForbidden();
    }

    public function test_inactive_user_is_refused(): void
    {
        $token = $this->obtainToken($this->jhon);
        $this->jhon->forceFill(['active' => false])->save();

        $this->rpc('tools/list', [], $token['access_token'])
            ->assertForbidden()
            ->assertJsonPath('error.message', 'Tu cuenta del Gestor TI está desactivada.');
    }

    public function test_revoked_token_is_refused(): void
    {
        $token = $this->obtainToken($this->jhon);
        Token::query()->where('user_id', $this->jhon->id)->update(['revoked' => true]);

        $this->rpc('tools/list', [], $token['access_token'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    }

    public function test_token_without_the_mcp_scope_is_refused(): void
    {
        Passport::actingAs($this->jhon, [], 'api');

        $this->initialize()->assertForbidden();
    }

    public function test_web_session_alone_does_not_open_the_server(): void
    {
        $this->actingAs($this->jhon, 'web');

        $this->initialize()->assertUnauthorized();
    }

    public function test_server_is_rate_limited_per_user(): void
    {
        config(['gestor.mcp.rate_limit_per_minute' => 2]);
        Passport::actingAs($this->jhon, ['mcp:use'], 'api');

        $this->rpc('tools/list')->assertOk();
        $this->rpc('tools/list')->assertOk();
        $this->rpc('tools/list')->assertStatus(429);
    }
}
