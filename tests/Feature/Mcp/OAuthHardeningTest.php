<?php

namespace Tests\Feature\Mcp;

use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Spatie\Activitylog\Models\Activity;

/**
 * Security review fixes of the connector's OAuth layer (E2b): PKCE S256
 * only, redirect host on the consent screen, pruning of unused DCR
 * clients and `mcp:revoke`.
 */
class OAuthHardeningTest extends McpTestCase
{
    /**
     * @param  array<string, string>  $pkce
     */
    private function authorizeWith(string $clientId, array $pkce): string
    {
        return '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::CLAUDE_CALLBACK,
            'scope' => 'mcp:use',
            'state' => 'xyz',
            ...$pkce,
        ]);
    }

    public function test_plain_pkce_is_refused(): void
    {
        $clientId = $this->registerClient()->json('client_id');
        $verifier = str_repeat('v', 64);

        $this->actingAs($this->jhon)
            ->get($this->authorizeWith($clientId, ['code_challenge' => $verifier, 'code_challenge_method' => 'plain']))
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_request'])
            ->assertJsonPath('hint', fn (string $hint) => str_contains($hint, 'S256'));

        $this->assertNull(session('authRequest'));
    }

    public function test_missing_challenge_method_is_refused(): void
    {
        $clientId = $this->registerClient()->json('client_id');

        // league/oauth2-server would default the method to `plain`.
        $this->actingAs($this->jhon)
            ->get($this->authorizeWith($clientId, ['code_challenge' => $this->pkce()['challenge']]))
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_request']);
    }

    public function test_confidential_client_also_needs_a_challenge(): void
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: 'Cliente confidencial',
            redirectUris: [self::CLAUDE_CALLBACK],
            confidential: true,
        );

        $this->actingAs($this->jhon)
            ->get($this->authorizeWith((string) $client->getKey(), []))
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_request']);
    }

    public function test_s256_flow_still_issues_a_token(): void
    {
        $token = $this->obtainToken($this->jhon);

        $this->initialize($token['access_token'])->assertOk();
    }

    public function test_consent_screen_shows_the_redirect_host(): void
    {
        $clientId = $this->registerClient([self::CLAUDE_CALLBACK], 'Claude')->json('client_id');

        $this->actingAs($this->jhon)
            ->get($this->authorizeUrl($clientId, self::CLAUDE_CALLBACK, $this->pkce()['challenge']))
            ->assertOk()
            ->assertSee('Volverá a: claude.ai')
            ->assertDontSee('en tu equipo')
            ->assertDontSee('Puedes retirar el acceso cuando quieras')
            ->assertSee('pide a TI que lo revoque');
    }

    public function test_consent_screen_warns_about_loopback_redirects(): void
    {
        $clientId = $this->registerClient(['http://localhost:33418/callback'], 'Claude Code')->json('client_id');

        $this->actingAs($this->jhon)
            ->get($this->authorizeUrl($clientId, 'http://localhost:51234/callback', $this->pkce()['challenge']))
            ->assertOk()
            ->assertSee('Volverá a: localhost (una aplicación en tu equipo, como Claude Code)')
            ->assertSee('Autoriza solo si acabas de conectar Claude Code en este equipo.');
    }

    public function test_prune_deletes_old_clients_that_never_got_a_token(): void
    {
        $stale = $this->registerClient([self::CLAUDE_CALLBACK], 'Viejo')->json('client_id');
        $used = $this->obtainToken($this->jhon)['client_id'];
        $pending = $this->registerClient([self::CLAUDE_CALLBACK], 'Con código')->json('client_id');
        AuthCode::query()->forceCreate([
            'id' => str_repeat('c', 80), 'user_id' => $this->jhon->id, 'client_id' => $pending,
            'scopes' => '["mcp:use"]', 'revoked' => false, 'expires_at' => now()->addMinutes(10),
        ]);

        $this->travel(25)->hours();
        $fresh = $this->registerClient([self::CLAUDE_CALLBACK], 'Nuevo')->json('client_id');

        $this->artisan('mcp:prune-clients')
            ->expectsOutputToContain('1')
            ->assertSuccessful();

        $this->assertNull(Client::query()->find($stale));
        $this->assertNotNull(Client::query()->find($used));
        $this->assertNotNull(Client::query()->find($pending));
        $this->assertNotNull(Client::query()->find($fresh));
    }

    public function test_prune_runs_daily_from_the_scheduler(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('mcp:prune-clients', Artisan::output());
    }

    public function test_revoke_cuts_the_access_and_refresh_tokens(): void
    {
        $token = $this->obtainToken($this->jhon);
        $other = $this->obtainToken($this->jhon);

        $this->artisan('mcp:revoke', ['email' => strtoupper($this->jhon->email)])
            ->expectsOutputToContain('2')
            ->assertSuccessful();

        $this->initialize($token['access_token'])->assertUnauthorized();
        $this->initialize($other['access_token'])->assertUnauthorized();

        $this->call('POST', '/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $token['client_id'],
            'refresh_token' => $token['refresh_token'],
        ], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_grant']);

        $this->assertSame(0, Token::query()->where('revoked', false)->count());
        $this->assertSame(0, RefreshToken::query()->where('revoked', false)->count());

        $audit = Activity::query()->where('event', 'mcp_access_revoked')->sole();
        $this->assertSame('auth', $audit->log_name);
        $this->assertSame($this->jhon->id, $audit->subject_id);
        $this->assertSame('console', $audit->getExtraProperty('source'));
        $this->assertSame(2, $audit->getExtraProperty('tokens'));
    }

    public function test_revoke_can_be_limited_to_one_client(): void
    {
        $kept = $this->obtainToken($this->jhon);
        $revoked = $this->obtainToken($this->jhon);

        $this->artisan('mcp:revoke', ['email' => $this->jhon->email, '--client' => $revoked['client_id']])
            ->assertSuccessful();

        $this->initialize($revoked['access_token'])->assertUnauthorized();
        $this->initialize($kept['access_token'])->assertOk();
    }

    public function test_revoke_with_an_unknown_email_fails(): void
    {
        $this->artisan('mcp:revoke', ['email' => 'nadie@example.com'])
            ->expectsOutputToContain('No existe')
            ->assertFailed();

        $this->assertSame(0, Activity::query()->where('event', 'mcp_access_revoked')->count());
    }
}
