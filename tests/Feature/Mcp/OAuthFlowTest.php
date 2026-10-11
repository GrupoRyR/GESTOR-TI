<?php

namespace Tests\Feature\Mcp;

use Laravel\Passport\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;

/**
 * Authorization code + PKCE flow of the connector: login through the
 * existing web login, Spanish consent screen, token endpoint, audit.
 */
class OAuthFlowTest extends McpTestCase
{
    public function test_full_flow_issues_a_token_that_opens_the_server(): void
    {
        $token = $this->obtainToken($this->jhon);

        $this->assertNotEmpty($token['refresh_token']);

        $stored = Token::query()->where('user_id', $this->jhon->id)->sole();
        $this->assertSame(['mcp:use'], $stored->scopes);
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $stored->expires_at->timestamp, 5);

        $this->initialize($token['access_token'])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'Gestor IA');
    }

    public function test_consent_screen_is_in_spanish_with_client_name(): void
    {
        $clientId = $this->registerClient([self::CLAUDE_CALLBACK], 'Claude')->json('client_id');
        $pkce = $this->pkce();

        $this->actingAs($this->jhon)
            ->get($this->authorizeUrl($clientId, self::CLAUDE_CALLBACK, $pkce['challenge']))
            ->assertOk()
            ->assertSee('Claude')
            ->assertSee('Consultar y actualizar el Gestor IA en tu nombre')
            ->assertSee('Autorizar')
            ->assertSee('Cancelar');
    }

    public function test_guest_is_sent_to_the_login_and_back(): void
    {
        $clientId = $this->registerClient()->json('client_id');
        $url = $this->authorizeUrl($clientId, self::CLAUDE_CALLBACK, $this->pkce()['challenge']);

        $this->get($url)->assertRedirect(route('login'));

        $intended = (string) session('url.intended');
        $this->assertStringStartsWith(url('/oauth/authorize?'), $intended);
        parse_str((string) parse_url($intended, PHP_URL_QUERY), $intendedQuery);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertEquals($query, $intendedQuery);
    }

    public function test_user_without_permission_is_refused_at_consent(): void
    {
        $clientId = $this->registerClient()->json('client_id');
        $url = $this->authorizeUrl($clientId, self::CLAUDE_CALLBACK, $this->pkce()['challenge']);

        $this->actingAs($this->paola)
            ->get($url)
            ->assertForbidden()
            ->assertSee('No tienes permiso para usar el conector del Gestor IA')
            ->assertDontSee('Autorizar');

        $this->assertNull(session('authRequest'));
    }

    public function test_user_without_permission_cannot_approve_a_pending_request(): void
    {
        $clientId = $this->registerClient()->json('client_id');
        $pkce = $this->pkce();

        // Jhon opens the consent screen; the session is then reused by a
        // user without the permission.
        $this->actingAs($this->jhon)
            ->get($this->authorizeUrl($clientId, self::CLAUDE_CALLBACK, $pkce['challenge']))
            ->assertOk();

        $this->actingAs($this->paola)
            ->post('/oauth/authorize', ['state' => 'xyz', 'client_id' => $clientId, 'auth_token' => session('authToken')])
            ->assertForbidden();
    }

    public function test_inactive_user_is_logged_out_at_consent(): void
    {
        $this->jhon->forceFill(['active' => false])->save();
        $clientId = $this->registerClient()->json('client_id');

        $this->actingAs($this->jhon)
            ->get($this->authorizeUrl($clientId, self::CLAUDE_CALLBACK, $this->pkce()['challenge']))
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_cancel_returns_access_denied_to_the_client(): void
    {
        $clientId = $this->registerClient()->json('client_id');

        $this->actingAs($this->jhon)
            ->get($this->authorizeUrl($clientId, self::CLAUDE_CALLBACK, $this->pkce()['challenge']))
            ->assertOk();

        $response = $this->delete('/oauth/authorize', ['state' => 'xyz', 'client_id' => $clientId, 'auth_token' => session('authToken')]);

        $response->assertRedirect();
        $this->assertStringStartsWith(self::CLAUDE_CALLBACK.'?', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('error=access_denied', (string) $response->headers->get('Location'));
    }

    public function test_token_request_without_the_pkce_verifier_fails(): void
    {
        $clientId = $this->registerClient()->json('client_id');
        $pkce = $this->pkce();

        $this->actingAs($this->jhon)->get($this->authorizeUrl($clientId, self::CLAUDE_CALLBACK, $pkce['challenge']));
        $location = (string) $this->post('/oauth/authorize', [
            'state' => 'xyz', 'client_id' => $clientId, 'auth_token' => session('authToken'),
        ])->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->call('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::CLAUDE_CALLBACK,
            'code' => $query['code'],
            'code_verifier' => str_repeat('a', 64),
        ], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(400);
    }

    public function test_authorization_without_pkce_is_refused(): void
    {
        $clientId = $this->registerClient()->json('client_id');

        $this->actingAs($this->jhon)
            ->get('/oauth/authorize?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => self::CLAUDE_CALLBACK,
                'scope' => 'mcp:use',
                'state' => 'xyz',
            ]))
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_request']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function loopbackPorts(): array
    {
        return [
            'localhost' => ['http://localhost:33418/callback', 'http://localhost:51234/callback'],
            '127.0.0.1' => ['http://127.0.0.1:33418/callback', 'http://127.0.0.1:60001/callback'],
            '[::1]' => ['http://[::1]:33418/callback', 'http://[::1]:40000/callback'],
        ];
    }

    #[DataProvider('loopbackPorts')]
    public function test_loopback_redirect_accepts_any_port(string $registered, string $used): void
    {
        $clientId = $this->registerClient([$registered], 'Claude Code')->json('client_id');
        $pkce = $this->pkce();

        $this->actingAs($this->jhon)
            ->get($this->authorizeUrl($clientId, $used, $pkce['challenge']))
            ->assertOk()
            ->assertSee('Claude Code');

        $location = (string) $this->post('/oauth/authorize', [
            'state' => 'xyz', 'client_id' => $clientId, 'auth_token' => session('authToken'),
        ])->headers->get('Location');

        $this->assertStringStartsWith($used.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->call('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $used,
            'code' => $query['code'],
            'code_verifier' => $pkce['verifier'],
        ], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mismatchedRedirects(): array
    {
        return [
            'loopback other path' => ['http://localhost:33418/callback', 'http://localhost:33418/other'],
            'loopback other host' => ['http://localhost:33418/callback', 'http://127.0.0.1:33418/callback'],
            'https other port' => [self::CLAUDE_CALLBACK, 'https://claude.ai:8443/api/mcp/auth_callback'],
            'https other path' => [self::CLAUDE_CALLBACK, 'https://claude.ai/api/mcp/other'],
        ];
    }

    #[DataProvider('mismatchedRedirects')]
    public function test_redirect_must_match_the_registered_one(string $registered, string $used): void
    {
        $clientId = $this->registerClient([$registered])->json('client_id');

        $this->actingAs($this->jhon)
            ->get($this->authorizeUrl($clientId, $used, $this->pkce()['challenge']))
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_client']);
    }

    public function test_authorization_and_token_issuance_are_audited_as_api(): void
    {
        $token = $this->obtainToken($this->jhon);

        $authorized = Activity::query()->where('event', 'mcp_client_authorized')->sole();
        $this->assertSame('auth', $authorized->log_name);
        $this->assertSame($this->jhon->id, $authorized->causer_id);
        $this->assertSame('api', $authorized->getExtraProperty('origin'));
        $this->assertSame($token['client_id'], $authorized->getExtraProperty('client_id'));
        $this->assertSame('Claude', $authorized->getExtraProperty('client_name'));

        $issued = Activity::query()->where('event', 'mcp_token_issued')->sole();
        $this->assertSame($this->jhon->id, $issued->causer_id);
        $this->assertSame('api', $issued->getExtraProperty('origin'));
        $this->assertSame($token['client_id'], $issued->getExtraProperty('client_id'));
        $this->assertStringNotContainsString($token['access_token'], json_encode($issued->properties));
    }

    public function test_refresh_token_grant_issues_a_new_token(): void
    {
        $token = $this->obtainToken($this->jhon);

        $this->call('POST', '/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $token['client_id'],
            'refresh_token' => $token['refresh_token'],
        ], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token']);
    }
}
