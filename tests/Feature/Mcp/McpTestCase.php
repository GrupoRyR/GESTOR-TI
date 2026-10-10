<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixture of the Gestor IA MCP connector (Stage E): real roles, a
 * throwaway Passport key pair (config strings, as in production env vars)
 * and helpers for the OAuth flow Claude runs (DCR, authorize + PKCE, token).
 */
abstract class McpTestCase extends TestCase
{
    use RefreshDatabase;

    public const SERVER_PATH = '/mcp/gestor-ia';

    public const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    protected Company $segurex;

    /** Líder IA: has `ai.mcp.use`. */
    protected User $jhon;

    /** Usuario final: no `ai.mcp.use`. */
    protected User $paola;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generatePassportKeys();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'slug' => 'segurex']);
        $this->jhon = $this->user('Líder IA', 'Jhon Castro');
        $this->paola = $this->user('Usuario final', 'Paola Ríos');
    }

    protected function user(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['company_id' => $this->segurex->id])->save();
        $user->assignRole($role);

        return $user;
    }

    /**
     * A minimal openssl config is passed so key generation also works on
     * Windows PHP builds without OPENSSL_CONF (same as GeneratesAppKey).
     */
    protected function generatePassportKeys(): void
    {
        $config = tempnam(sys_get_temp_dir(), 'pp-cnf-');
        file_put_contents($config, "[req]\ndistinguished_name = req_dn\n[req_dn]\n");
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config];

        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $private, null, $options);
        @unlink($config);

        config([
            'passport.private_key' => $private,
            'passport.public_key' => openssl_pkey_get_details($key)['key'],
        ]);
    }

    /**
     * Dynamic client registration, as Claude does it.
     *
     * @param  list<string>  $redirectUris
     */
    protected function registerClient(array $redirectUris = [self::CLAUDE_CALLBACK], string $name = 'Claude'): TestResponse
    {
        return $this->postJson('/oauth/register', [
            'client_name' => $name,
            'redirect_uris' => $redirectUris,
        ]);
    }

    /**
     * @return array{verifier: string, challenge: string}
     */
    protected function pkce(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    protected function authorizeUrl(string $clientId, string $redirectUri, string $challenge, string $state = 'xyz'): string
    {
        return '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'mcp:use',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => url(self::SERVER_PATH),
        ]);
    }

    /**
     * Full authorization code + PKCE flow through the real endpoints
     * (consent screen, approval, form-urlencoded token request).
     *
     * @return array{access_token: string, refresh_token: string, client_id: string}
     */
    protected function obtainToken(User $user, string $redirectUri = self::CLAUDE_CALLBACK): array
    {
        $clientId = $this->registerClient([$redirectUri])->assertCreated()->json('client_id');
        $pkce = $this->pkce();

        $this->actingAs($user)
            ->get($this->authorizeUrl($clientId, $redirectUri, $pkce['challenge']))
            ->assertOk();

        $authToken = session('authToken');
        $approved = $this->post('/oauth/authorize', [
            'state' => 'xyz',
            'client_id' => $clientId,
            'auth_token' => $authToken,
        ]);

        $approved->assertRedirect();
        $location = (string) $approved->headers->get('Location');
        $this->assertStringStartsWith($redirectUri, $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state'] ?? null);

        $token = $this->call('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code' => $query['code'],
            'code_verifier' => $pkce['verifier'],
        ], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json']);

        $token->assertOk();

        // The MCP calls that follow authenticate only with the bearer token.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return [
            'access_token' => $token->json('access_token'),
            'refresh_token' => $token->json('refresh_token'),
            'client_id' => $clientId,
        ];
    }

    /**
     * One JSON-RPC message to the Gestor IA server (plain JSON per POST).
     *
     * @param  array<string, mixed>  $params
     */
    protected function rpc(string $method, array $params = [], ?string $token = null, int $id = 1): TestResponse
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->postJson(self::SERVER_PATH, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], $headers);
    }

    protected function initialize(?string $token = null): TestResponse
    {
        return $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'claude-ai', 'version' => '1.0'],
        ], $token);
    }
}
