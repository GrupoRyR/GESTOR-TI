<?php

namespace Tests\Feature\Admin;

use App\Mail\IntegrationTestMail;
use App\Models\AiInteraction;
use App\Models\Company;
use App\Models\TelegramLink;
use App\Models\User;
use App\Support\EmployeeSyncStatus;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Administración › Integraciones (`Admin/Integrations/Index`, types/pages/admin.ts).
 */
class IntegrationsAdminTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = 'bot-secret-token-123';

    private const DEEPSEEK_KEY = 'sk-deepseek-secret-456';

    private const MS_SECRET = 'ms-client-secret-789';

    private Company $segurex;

    private Company $derivados;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'services.microsoft.client_id' => 'client-id-1',
            'services.microsoft.client_secret' => self::MS_SECRET,
            'services.telegram.bot_token' => self::BOT_TOKEN,
            'services.telegram.bot_username' => 'GestorRyrBot',
            'services.deepseek.api_key' => self::DEEPSEEK_KEY,
            'services.github.install_url' => 'https://github.com/apps/gestor-ryr/installations/new',
        ]);

        $this->segurex = Company::factory()->create([
            'name' => 'Segurex Latam', 'short_name' => 'Segurex', 'sender_email' => 'segurex.info@segurex.com',
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]);
        $this->derivados = Company::factory()->create([
            'name' => 'Soluciones y Derivados', 'short_name' => 'Derivados', 'sender_email' => 'derivados.info@derivadoscol.com',
            'tenant_id' => '22222222-2222-2222-2222-222222222222',
        ]);

        $this->admin = User::factory()->microsoft()->create([
            'name' => 'Jhon Castro',
            'email' => 'jhon.castro@segurex.com',
            'company_id' => $this->segurex->id,
        ]);
        $this->admin->assignRole('Súper Admin');
    }

    private function fakeWebhook(array $info = ['url' => 'https://gestor.test/api/telegram/webhook/x', 'pending_update_count' => 0]): void
    {
        Http::fake([
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => $info]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]]),
        ]);
    }

    private function settings(): Settings
    {
        return app(Settings::class);
    }

    public function test_index_requires_settings_manage(): void
    {
        $this->get(route('admin.integrations.index'))->assertRedirect(route('login'));

        $member = tap(User::factory()->microsoft()->create(['company_id' => $this->segurex->id]))->assignRole('Usuario final');
        $this->actingAs($member)->get(route('admin.integrations.index'))->assertForbidden();

        $companiesOnly = tap(User::factory()->microsoft()->create(['company_id' => $this->segurex->id]))
            ->givePermissionTo(['admin.access', 'companies.manage']);
        $this->actingAs($companiesOnly)->get(route('admin.integrations.index'))->assertForbidden();
    }

    public function test_index_returns_exact_props(): void
    {
        $this->fakeWebhook();
        $this->travelTo('2026-10-20 10:00:00');

        app(EmployeeSyncStatus::class)->recordSuccess($this->segurex->id, ['created' => 2, 'updated' => 5, 'deactivated' => 0]);
        app(EmployeeSyncStatus::class)->recordFailure($this->derivados->id, 'Falta el permiso User.Read.All.');
        $noTenant = Company::factory()->create(['name' => 'Zeta', 'short_name' => 'Zeta', 'tenant_id' => null, 'sender_email' => null]);
        Company::factory()->inactive()->create(['name' => 'Antigua']);

        TelegramLink::query()->create(['user_id' => $this->admin->id, 'chat_id' => '777', 'linked_at' => now()]);
        TelegramLink::query()->create(['user_id' => User::factory()->create()->id, 'chat_id' => null, 'link_token' => 'x']);
        $this->settings()->set('telegram.last_update_at', '2026-10-20T09:41:00-05:00');
        $this->settings()->set('github.organization', 'GrupoRyR');
        $this->settings()->set('ai.monthly_cost_cap_usd', 10.0);

        AiInteraction::query()->create(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'feature' => 'assistant', 'status' => 'ok', 'cost' => '1.25']);
        AiInteraction::query()->create(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'feature' => 'assistant', 'status' => 'ok', 'cost' => '2.15']);
        tap(AiInteraction::query()->create(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'feature' => 'assistant', 'status' => 'ok', 'cost' => '50']))
            ->forceFill(['created_at' => '2026-09-30 20:00:00'])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.integrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Integrations/Index')
                ->has('microsoft.companies', 3)
                ->where('microsoft.companies.0', [
                    'company_id' => $this->segurex->id,
                    'status' => 'ok',
                    'last_sync_at' => '2026-10-20T10:00:00-05:00',
                    'synced_count' => 0,
                    'error' => null,
                ])
                ->where('microsoft.companies.1', [
                    'company_id' => $this->derivados->id,
                    'status' => 'error',
                    'last_sync_at' => null,
                    'synced_count' => null,
                    'error' => 'Falta el permiso User.Read.All.',
                ])
                ->where('microsoft.companies.2.company_id', $noTenant->id)
                ->where('microsoft.companies.2.status', 'never')
                ->where('microsoft.companies.2.error', 'La empresa no tiene inquilino de Microsoft: agrégalo en Empresas.')
                ->where('mail', [
                    'status' => 'pending',
                    'transport' => null,
                    'senders' => [
                        ['company_id' => $this->segurex->id, 'email' => 'segurex.info@segurex.com'],
                        ['company_id' => $this->derivados->id, 'email' => 'derivados.info@derivadoscol.com'],
                    ],
                    'note' => 'Falta configurar el envío (Microsoft Graph o SMTP) en el servidor. Mientras tanto los avisos van por Telegram y la campana.',
                ])
                ->where('telegram', [
                    'status' => 'ok',
                    'bot_username' => 'GestorRyrBot',
                    'linked_users' => 1,
                    'last_message_at' => '2026-10-20T09:41:00-05:00',
                    'webhook_ok' => true,
                ])
                ->where('github', [
                    'status' => 'disconnected',
                    'organization' => 'GrupoRyR',
                    'install_url' => 'https://github.com/apps/gestor-ryr/installations/new',
                    'repositories_count' => 0,
                ])
                ->where('ai.status', 'ok')
                ->where('ai.provider', 'deepseek')
                ->where('ai.model', 'deepseek-chat')
                ->where('ai.provider_options', [
                    ['value' => 'deepseek', 'label' => 'DeepSeek'],
                    ['value' => 'openai', 'label' => 'OpenAI'],
                    ['value' => 'anthropic', 'label' => 'Anthropic'],
                    ['value' => 'gemini', 'label' => 'Google Gemini'],
                    ['value' => 'mistral', 'label' => 'Mistral'],
                    ['value' => 'openrouter', 'label' => 'OpenRouter'],
                    ['value' => 'ollama', 'label' => 'Ollama (local)'],
                ])
                ->where('ai.model_options', [
                    ['value' => 'deepseek-chat', 'label' => 'deepseek-chat'],
                    ['value' => 'deepseek-reasoner', 'label' => 'deepseek-reasoner'],
                ])
                ->where('ai.monthly_cap_usd', 10)
                ->where('ai.month_spend_usd', 3.4)
                ->where('ai.warn_ratio', 0.8)
                ->has('ai', 8));
    }

    public function test_props_never_contain_secrets(): void
    {
        $this->fakeWebhook();

        $content = $this->actingAs($this->admin)->get(route('admin.integrations.index'))->assertOk()->getContent();

        foreach ([self::BOT_TOKEN, self::DEEPSEEK_KEY, self::MS_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    public function test_unconfigured_integrations(): void
    {
        config([
            'services.telegram.bot_token' => null,
            'services.telegram.bot_username' => null,
            'services.deepseek.api_key' => null,
            'services.microsoft.client_secret' => null,
        ]);
        Http::fake();

        $this->actingAs($this->admin)
            ->get(route('admin.integrations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('microsoft.companies.0.status', 'never')
                ->where('microsoft.companies.0.error', 'Faltan las credenciales de la aplicación de Microsoft (MICROSOFT_CLIENT_ID y MICROSOFT_CLIENT_SECRET) en el servidor.')
                ->where('telegram', [
                    'status' => 'off',
                    'bot_username' => null,
                    'linked_users' => 0,
                    'last_message_at' => null,
                    'webhook_ok' => false,
                ])
                ->where('github', ['status' => 'disconnected', 'organization' => 'GrupoRyR', 'install_url' => 'https://github.com/apps/gestor-ryr/installations/new', 'repositories_count' => 0])
                ->where('ai.status', 'off')
                ->where('ai.monthly_cap_usd', 10) // default cap (GESTOR_AI_MONTHLY_CAP_USD)
                ->where('ai.month_spend_usd', 0)
                ->etc());

        Http::assertNothingSent();
    }

    public function test_mail_status_follows_the_mailer_and_the_last_test(): void
    {
        $this->fakeWebhook();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.office365.com']);

        $this->actingAs($this->admin)->get(route('admin.integrations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('mail.status', 'ok')
                ->where('mail.transport', 'smtp')
                ->where('mail.note', null)
                ->etc());

        $this->settings()->set('integrations.mail.last_test', ['ok' => false, 'at' => now()->toIso8601String()]);

        $this->actingAs($this->admin)->get(route('admin.integrations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('mail.status', 'error')
                ->where('mail.note', 'Falló el último correo de prueba. Revisa la configuración de correo del servidor y vuelve a probar.')
                ->etc());
    }

    public function test_telegram_webhook_failures_and_caching(): void
    {
        $this->fakeWebhook(['url' => '', 'pending_update_count' => 3]);

        $this->actingAs($this->admin)->get(route('admin.integrations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('telegram.status', 'error')
                ->where('telegram.webhook_ok', false)
                ->etc());
        $this->actingAs($this->admin)->get(route('admin.integrations.index'))->assertOk();

        // Cached for 10 minutes: one getWebhookInfo for two page loads.
        Http::assertSentCount(1);

        $this->travel(11)->minutes();
        $this->actingAs($this->admin)->get(route('admin.integrations.index'))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_recent_webhook_errors_mark_telegram_as_failing(): void
    {
        $this->fakeWebhook(['url' => 'https://gestor.test/hook', 'last_error_date' => now()->subMinutes(5)->timestamp, 'last_error_message' => 'Bad Gateway']);

        $this->actingAs($this->admin)->get(route('admin.integrations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('telegram.status', 'error')->where('telegram.webhook_ok', false)->etc());
    }

    public function test_ai_is_capped_when_the_month_spend_reaches_the_cap(): void
    {
        $this->fakeWebhook();
        $this->settings()->set('ai.monthly_cost_cap_usd', 2.0);
        $this->settings()->set('ai.cost_warning_percent', 75);
        AiInteraction::query()->create(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'feature' => 'assistant', 'status' => 'ok', 'cost' => '2.5']);

        $this->actingAs($this->admin)->get(route('admin.integrations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('ai.status', 'capped')
                ->where('ai.monthly_cap_usd', 2)
                ->where('ai.month_spend_usd', 2.5)
                ->where('ai.warn_ratio', 0.75)
                ->etc());
    }

    public function test_microsoft_test_requests_a_fresh_token_per_company(): void
    {
        Http::fake([
            'login.microsoftonline.com/11111111-*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'login.microsoftonline.com/22222222-*' => Http::response(['error' => 'unauthorized_client'], 400),
        ]);
        Company::factory()->create(['tenant_id' => null]);

        $this->actingAs($this->admin)
            ->from(route('admin.integrations.index'))
            ->post(route('admin.integrations.microsoft.test'))
            ->assertRedirect(route('admin.integrations.index'))
            ->assertSessionHas('error', 'Segurex: conectó. Derivados: Microsoft rechazó la conexión (código graph_400).');

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_contains((string) $request->url(), 'graph.microsoft.com'));
    }

    public function test_microsoft_test_success_and_missing_credentials(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600])]);

        $this->actingAs($this->admin)
            ->post(route('admin.integrations.microsoft.test'))
            ->assertSessionHas('success', 'Segurex: conectó. Derivados: conectó.');

        // A second test does not reuse the cached token.
        $this->actingAs($this->admin)->post(route('admin.integrations.microsoft.test'));
        Http::assertSentCount(4);

        config(['services.microsoft.client_secret' => null]);

        $this->actingAs($this->admin)
            ->post(route('admin.integrations.microsoft.test'))
            ->assertSessionHas('error', 'Faltan las credenciales de la aplicación de Microsoft (MICROSOFT_CLIENT_ID y MICROSOFT_CLIENT_SECRET) en el servidor.');
        Http::assertSentCount(4);
    }

    public function test_mail_test_sends_to_the_acting_user_from_their_company(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.office365.com']);

        $this->actingAs($this->admin)
            ->post(route('admin.integrations.mail.test'))
            ->assertSessionHas('success', 'Te enviamos un correo de prueba a jhon.castro@segurex.com.');

        Mail::assertSent(IntegrationTestMail::class, fn (IntegrationTestMail $mail) => $mail->hasTo('jhon.castro@segurex.com')
            && $mail->hasFrom('segurex.info@segurex.com'));
        $this->assertTrue($this->settings()->get('integrations.mail.last_test')['ok']);
    }

    public function test_mail_test_without_a_mailer_is_refused(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.integrations.mail.test'))
            ->assertSessionHas('error', 'El correo todavía no está configurado en el servidor (Microsoft Graph o SMTP).');

        Mail::assertNothingSent();
    }

    public function test_mail_test_failures_are_recorded_without_details(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.office365.com']);
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('535 auth failed for '.self::MS_SECRET));

        $this->actingAs($this->admin)
            ->post(route('admin.integrations.mail.test'))
            ->assertSessionHas('error', 'No se pudo enviar el correo de prueba. Revisa la configuración de correo del servidor.');

        $this->assertFalse($this->settings()->get('integrations.mail.last_test')['ok']);
    }

    public function test_telegram_test_sends_to_the_linked_chat_and_refreshes_the_webhook(): void
    {
        $this->fakeWebhook();
        TelegramLink::query()->create(['user_id' => $this->admin->id, 'chat_id' => '777', 'linked_at' => now()]);

        $this->actingAs($this->admin)->get(route('admin.integrations.index'));
        $this->actingAs($this->admin)
            ->post(route('admin.integrations.telegram.test'))
            ->assertSessionHas('success', 'Te enviamos un mensaje de prueba por Telegram.');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '777');
        // Page load + refresh after the test.
        $this->assertSame(2, collect(Http::recorded())->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/getWebhookInfo'))->count());
    }

    public function test_telegram_test_needs_a_linked_chat_and_a_bot(): void
    {
        $this->fakeWebhook();

        $this->actingAs($this->admin)
            ->post(route('admin.integrations.telegram.test'))
            ->assertSessionHas('error', 'Vincula tu Telegram en «Perfil y notificaciones» para recibir el mensaje de prueba.');

        config(['services.telegram.bot_token' => null]);
        $this->actingAs($this->admin)
            ->post(route('admin.integrations.telegram.test'))
            ->assertSessionHas('error', 'El bot de Telegram no está configurado.');

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_telegram_test_reports_a_rejected_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);
        TelegramLink::query()->create(['user_id' => $this->admin->id, 'chat_id' => '777', 'linked_at' => now()]);

        $this->actingAs($this->admin)
            ->post(route('admin.integrations.telegram.test'))
            ->assertSessionHas('error', 'Telegram no aceptó el mensaje de prueba. Revisa el bot y vuelve a intentar.');
    }

    public function test_the_telegram_bot_is_named_ingenierito_and_its_username_comes_from_the_environment(): void
    {
        $this->assertSame('Ingenierito', config('services.telegram.bot_name'));

        config(['services.telegram.bot_username' => '@IngenieritoRyrBot']);
        Http::fake();
        $this->actingAs($this->admin)
            ->get(route('admin.integrations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('telegram.bot_username', 'IngenieritoRyrBot')->etc());
    }

    public function test_github_update_validates_and_saves_the_organization(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.integrations.index'))
            ->put(route('admin.integrations.github.update'), ['organization' => 'not a slug!'])
            ->assertRedirect(route('admin.integrations.index'))
            ->assertSessionHasErrors('organization');

        $this->actingAs($this->admin)
            ->put(route('admin.integrations.github.update'), ['organization' => '-bad-'])
            ->assertSessionHasErrors('organization');

        $this->actingAs($this->admin)
            ->put(route('admin.integrations.github.update'), ['organization' => 'OtraCuenta'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Organización de GitHub guardada.');

        $this->assertSame('OtraCuenta', $this->settings()->get('github.organization'));
        $activity = Activity::query()->where('event', 'github_settings_updated')->sole();
        $this->assertSame('integrations', $activity->log_name);
        // The confirmed account (GITHUB_ACCOUNT, default GrupoRyR) is the starting value.
        $this->assertSame(['organization' => 'GrupoRyR'], $activity->properties['old']);
        $this->assertSame(['organization' => 'OtraCuenta'], $activity->properties['attributes']);
    }

    public function test_ai_update_validates_saves_and_logs(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.integrations.ai.update'), ['provider' => 'skynet', 'model' => '', 'monthly_cap_usd' => -1])
            ->assertSessionHasErrors(['provider', 'model', 'monthly_cap_usd']);

        $this->actingAs($this->admin)
            ->put(route('admin.integrations.ai.update'), ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'monthly_cap_usd' => 25.5])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Proveedor de IA guardado.');

        $this->assertSame('anthropic', $this->settings()->get('ai.provider'));
        $this->assertSame('claude-sonnet-4-5', $this->settings()->get('ai.model'));
        $this->assertSame(25.5, $this->settings()->get('ai.monthly_cost_cap_usd'));

        $activity = Activity::query()->where('event', 'ai_settings_updated')->sole();
        $this->assertSame('integrations', $activity->log_name);
        $this->assertTrue($activity->causer->is($this->admin));
        $this->assertSame(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'monthly_cap_usd' => 10], $activity->properties['old']);
        $this->assertSame(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'monthly_cap_usd' => 25.5], $activity->properties['attributes']);
        $this->assertContains('ai_settings_updated', config('gestor.audit.sensitive_events'));

        // Null cap removes it; no change → no new entry.
        $this->actingAs($this->admin)
            ->put(route('admin.integrations.ai.update'), ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'monthly_cap_usd' => null])
            ->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $this->settings()->get('ai.monthly_cost_cap_usd'));
        $this->actingAs($this->admin)
            ->put(route('admin.integrations.ai.update'), ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'monthly_cap_usd' => null]);
        $this->assertSame(2, Activity::query()->where('event', 'ai_settings_updated')->count());
    }

    public function test_actions_require_settings_manage(): void
    {
        $member = tap(User::factory()->microsoft()->create(['company_id' => $this->segurex->id]))->assignRole('Usuario final');

        $this->actingAs($member)->post(route('admin.integrations.microsoft.test'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.integrations.mail.test'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.integrations.telegram.test'))->assertForbidden();
        $this->actingAs($member)->put(route('admin.integrations.github.update'), ['organization' => 'x'])->assertForbidden();
        $this->actingAs($member)->put(route('admin.integrations.ai.update'), ['provider' => 'deepseek', 'model' => 'x'])->assertForbidden();
    }

    public function test_test_actions_are_rate_limited(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600])]);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->admin)->post(route('admin.integrations.microsoft.test'))->assertRedirect();
        }

        $this->actingAs($this->admin)->post(route('admin.integrations.microsoft.test'))->assertTooManyRequests();
    }
}
