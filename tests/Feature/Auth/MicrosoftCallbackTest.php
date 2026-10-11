<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\SessionController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Mockery;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;
use SocialiteProviders\Microsoft\Provider;
use Tests\TestCase;

class MicrosoftCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft.client_id' => 'test-client',
            'services.microsoft.client_secret' => 'test-secret',
            'services.microsoft.redirect' => 'http://localhost/auth/microsoft/callback',
            'services.microsoft.tenant' => 'organizations',
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);

        Company::factory()->create(['tenant_id' => self::TENANT, 'domains' => ['segurex.com']]);
    }

    private function fakeMicrosoft(array $raw = [], ?string $tid = self::TENANT): void
    {
        $raw = array_merge([
            'id' => 'azure-ana',
            'displayName' => 'Ana Pérez',
            'userPrincipalName' => 'ana@segurex.com',
            'mail' => 'ana@segurex.com',
        ], $raw);

        $user = (new SocialiteUser)->setRaw($raw)->map([
            'id' => $raw['id'],
            'name' => $raw['displayName'],
            'email' => $raw['userPrincipalName'],
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($user);
        $provider->shouldReceive('getClaims')->andReturn($tid === null ? null : (object) ['tid' => $tid]);

        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);
    }

    public function test_redirect_goes_to_microsoft_organizations_with_scopes(): void
    {
        $response = $this->get(route('auth.microsoft.redirect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertEqualsCanonicalizing(['openid', 'profile', 'email', 'User.Read'], explode(' ', $query['scope']));
        $this->assertArrayNotHasKey('prompt', $query);
    }

    public function test_callback_logs_in_and_redirects_to_home(): void
    {
        $this->fakeMicrosoft();

        $this->get(route('auth.microsoft.callback'))
            ->assertRedirect(route('home'));

        $user = User::where('azure_id', 'azure-ana')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('Usuario final'));
    }

    public function test_callback_redirects_to_intended_url(): void
    {
        $this->fakeMicrosoft();

        $this->withSession(['url.intended' => 'http://localhost/ai'])
            ->get(route('auth.microsoft.callback'))
            ->assertRedirect('http://localhost/ai');
    }

    /**
     * @return array<string, string>
     */
    private function redirectQuery(string $uri): array
    {
        $location = (string) $this->get($uri)->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_redirect_passes_allowed_prompt_values(): void
    {
        $this->assertSame('select_account', $this->redirectQuery(route('auth.microsoft.redirect', ['prompt' => 'select_account']))['prompt'] ?? null);
        $this->assertSame('login', $this->redirectQuery(route('auth.microsoft.redirect', ['prompt' => 'login']))['prompt'] ?? null);
    }

    public function test_redirect_ignores_unknown_prompt_values(): void
    {
        $this->assertArrayNotHasKey('prompt', $this->redirectQuery(route('auth.microsoft.redirect', ['prompt' => 'consent'])));
        $this->assertArrayNotHasKey('prompt', $this->redirectQuery(route('auth.microsoft.redirect', ['prompt' => ['select_account']])));
    }

    public function test_unknown_tenant_shows_company_not_enabled_with_the_microsoft_email(): void
    {
        Log::spy();
        $this->fakeMicrosoft(['mail' => 'Laura@ProveedorX.com'], tid: '99999999-9999-9999-9999-999999999999');

        $this->get(route('auth.microsoft.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas(SessionController::COMPANY_NOT_ENABLED, 'laura@proveedorx.com')
            ->assertSessionMissing('error');

        $this->assertGuest();
        $this->assertSame(0, User::count());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'Microsoft login rejected'));
    }

    public function test_domain_outside_the_company_shows_company_not_enabled(): void
    {
        $this->fakeMicrosoft(['mail' => 'ana@otro.com', 'userPrincipalName' => 'ana@otro.com']);

        $this->get(route('auth.microsoft.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas(SessionController::COMPANY_NOT_ENABLED, 'ana@otro.com');

        $this->assertGuest();
    }

    public function test_guest_accounts_get_tenant_not_allowed(): void
    {
        $this->fakeMicrosoft([
            'userPrincipalName' => 'ana_gmail.com#EXT#@segurex.onmicrosoft.com',
            'mail' => 'ana@gmail.com',
        ]);

        $this->get(route('auth.microsoft.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas(SessionController::LOGIN_ERROR, 'tenant_not_allowed')
            ->assertSessionMissing(SessionController::COMPANY_NOT_ENABLED);

        $this->assertGuest();
    }

    public function test_missing_identity_gets_microsoft_failed(): void
    {
        $this->fakeMicrosoft(['id' => '']);

        $this->get(route('auth.microsoft.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas(SessionController::LOGIN_ERROR, 'microsoft_failed');

        $this->assertGuest();
    }

    public function test_provider_errors_get_microsoft_failed(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('microsoft')->andReturn($provider);

        $this->get(route('auth.microsoft.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas(SessionController::LOGIN_ERROR, 'microsoft_failed')
            ->assertSessionMissing('error');

        $this->assertGuest();
    }
}
