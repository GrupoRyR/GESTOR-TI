<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RequireRecentMicrosoftAuth;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use SocialiteProviders\Microsoft\Provider;
use Tests\TestCase;

class ReauthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-1111-1111-1111-111111111111';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft.client_id' => 'test-client',
            'services.microsoft.client_secret' => 'test-secret',
            'services.microsoft.redirect' => 'http://localhost/auth/microsoft/callback',
        ]);

        Route::middleware(['web', 'auth', 'reauth:10'])
            ->get('/_test/vault', fn () => 'vault-open')
            ->name('test.vault');

        $company = Company::factory()->create(['tenant_id' => self::TENANT, 'domains' => ['segurex.com']]);

        $this->user = User::factory()->create(['email' => 'jefe@segurex.com', 'company_id' => $company->id]);
        $this->user->forceFill(['azure_id' => 'azure-jefe', 'tenant_id' => self::TENANT])->save();
    }

    private function fakeMicrosoft(string $azureId): void
    {
        $user = (new SocialiteUser)->setRaw([
            'id' => $azureId,
            'displayName' => 'Jefe',
            'userPrincipalName' => 'jefe@segurex.com',
            'mail' => 'jefe@segurex.com',
        ])->map(['id' => $azureId, 'name' => 'Jefe', 'email' => 'jefe@segurex.com']);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($user);
        $provider->shouldReceive('getClaims')->andReturn((object) ['tid' => self::TENANT]);

        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);
    }

    public function test_middleware_alias_is_registered(): void
    {
        $this->assertSame(RequireRecentMicrosoftAuth::class, app(HttpKernel::class)->getMiddlewareAliases()['reauth']);
    }

    public function test_without_recent_reauth_redirects_to_reauth(): void
    {
        $this->actingAs($this->user)
            ->get('/_test/vault')
            ->assertRedirect(route('auth.microsoft.reauth'))
            ->assertSessionHas('url.intended', url('/_test/vault'));
    }

    public function test_stale_reauth_redirects_to_reauth(): void
    {
        $this->actingAs($this->user)
            ->withSession(['auth.reauthenticated_at' => now()->subMinutes(11)->getTimestamp()])
            ->get('/_test/vault')
            ->assertRedirect(route('auth.microsoft.reauth'));
    }

    public function test_fresh_reauth_passes(): void
    {
        $this->actingAs($this->user)
            ->withSession(['auth.reauthenticated_at' => now()->subMinutes(9)->getTimestamp()])
            ->get('/_test/vault')
            ->assertOk()
            ->assertSee('vault-open');
    }

    public function test_reauth_redirects_to_microsoft_with_prompt_login_and_marker(): void
    {
        $response = $this->actingAs($this->user)->get(route('auth.microsoft.reauth'));

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('login', $query['prompt']);
        $response->assertSessionHas('auth.reauth_azure_id', 'azure-jefe');
    }

    public function test_reauth_requires_authentication(): void
    {
        $this->get(route('auth.microsoft.reauth'))->assertRedirect(route('login'));
    }

    public function test_reauth_is_forbidden_without_microsoft_identity(): void
    {
        $local = User::factory()->create();

        $this->actingAs($local)->get(route('auth.microsoft.reauth'))->assertForbidden();
    }

    public function test_callback_with_marker_sets_timestamp_and_returns_to_intended(): void
    {
        $this->fakeMicrosoft('azure-jefe');
        $this->freezeTime();

        $this->actingAs($this->user)
            ->withSession(['auth.reauth_azure_id' => 'azure-jefe', 'url.intended' => 'http://localhost/_test/vault'])
            ->get(route('auth.microsoft.callback'))
            ->assertRedirect('http://localhost/_test/vault')
            ->assertSessionHas('auth.reauthenticated_at', now()->getTimestamp())
            ->assertSessionMissing('auth.reauth_azure_id');

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_callback_with_different_azure_id_is_rejected(): void
    {
        $this->fakeMicrosoft('azure-someone-else');

        $this->actingAs($this->user)
            ->withSession(['auth.reauth_azure_id' => 'azure-jefe', 'url.intended' => 'http://localhost/_test/vault'])
            ->get(route('auth.microsoft.callback'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', 'La verificación no corresponde a tu cuenta de Microsoft.')
            ->assertSessionMissing('auth.reauthenticated_at');

        $this->assertAuthenticatedAs($this->user);
    }
}
