<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessGitHubEvent;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\Company;
use App\Models\GitHubRepository;
use App\Models\GitHubWebhookEvent;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Administración › Integraciones › GitHub (`Admin/Integrations/GitHub`,
 * `AdminIntegrationsGitHubProps` in types/pages/admin.ts).
 */
class GitHubIntegrationAdminTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec-github-secret-123';

    private const INSTALL_URL = 'https://github.com/apps/gestor-ryr/installations/new';

    private Company $segurex;

    private User $admin;

    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->keyPath = tempnam(sys_get_temp_dir(), 'gh-key-');
        file_put_contents($this->keyPath, '-----BEGIN PRIVATE KEY-----');

        config([
            'app.url' => 'https://gestor.test',
            'services.github.install_url' => self::INSTALL_URL,
            'services.github.app_id' => '123456',
            'services.github.app_name' => 'Gestor TI RYR',
            'services.github.private_key_path' => $this->keyPath,
            'services.github.webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->admin = tap(User::factory()->microsoft()->create(['company_id' => $this->segurex->id]))->assignRole('Súper Admin');

        Http::fake();
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);

        parent::tearDown();
    }

    private function settings(): Settings
    {
        return app(Settings::class);
    }

    private function connect(): void
    {
        $this->settings()->set('github.organization', 'GrupoRyR');
        $this->settings()->set('github.installation_id', 42);
    }

    private function event(array $attributes): GitHubWebhookEvent
    {
        return GitHubWebhookEvent::factory()->create($attributes);
    }

    public function test_the_page_requires_ai_github_manage(): void
    {
        $this->get(route('admin.integrations.github'))->assertRedirect(route('login'));

        $member = tap(User::factory()->microsoft()->create(['company_id' => $this->segurex->id]))->assignRole('Usuario final');
        $this->actingAs($member)->get(route('admin.integrations.github'))->assertForbidden();

        $settingsOnly = tap(User::factory()->microsoft()->create(['company_id' => $this->segurex->id]))
            ->givePermissionTo(['admin.access', 'settings.manage']);
        $this->actingAs($settingsOnly)->get(route('admin.integrations.github'))->assertForbidden();

        // Líder IA has `ai.github.manage` but not `settings.manage`.
        $leader = tap(User::factory()->microsoft()->create(['company_id' => $this->segurex->id]))->assignRole('Líder IA');
        $this->actingAs($leader)->get(route('admin.integrations.github'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Integrations/GitHub'));
    }

    public function test_not_connected_page_props(): void
    {
        config(['services.github.private_key_path' => null, 'services.github.webhook_secret' => null]);
        $this->travelTo('2026-10-20 10:00:00');
        GitHubRepository::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.integrations.github'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Integrations/GitHub')
                ->where('connection', [
                    'connected' => false,
                    'kind' => 'app',
                    'app_name' => 'Gestor TI RYR',
                    'organization' => '[ORG]',
                    'installed_repos_count' => 0,
                    'permissions' => [
                        ['scope' => 'issues', 'access' => 'write'],
                        ['scope' => 'pull_requests', 'access' => 'read'],
                        ['scope' => 'contents', 'access' => 'write'],
                        ['scope' => 'releases', 'access' => 'read'],
                        ['scope' => 'actions', 'access' => 'read'],
                        ['scope' => 'administration', 'access' => 'write'],
                    ],
                    'private_key_configured' => false,
                    'manage_url' => 'https://github.com/organizations/[ORG]/settings/installations',
                    'webhook' => [
                        'url' => 'https://gestor.test/api/github/webhook',
                        'configured' => false,
                        'events' => ['issues', 'pull_request', 'release', 'workflow_run', 'push'],
                        'last_delivery_at' => null,
                    ],
                ])
                ->where('install_url', null)
                ->where('repos', [])
                ->where('stats', ['month' => 'octubre', 'received' => 0, 'processed' => 0, 'failed' => 0])
                ->where('failed_events', [])
                ->where('now', '2026-10-20T10:00:00-05:00'));
    }

    public function test_connected_page_props(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $this->connect();

        $product = AiProduct::factory()->create(['slug' => 'portafolio', 'name' => 'Portafolio de productos', 'repository' => 'GrupoRyR/portafolio']);
        $robot = AiProduct::factory()->create(['slug' => 'robotcamara', 'name' => 'Robot cámara', 'repository' => 'grupoRyR/RobotCamara']);
        $project = AiProject::factory()->create(['company_id' => $this->segurex->id, 'name' => 'Visualizador de cartera']);

        GitHubRepository::factory()->create([
            'full_name' => 'GrupoRyR/portafolio', 'name' => 'portafolio', 'private' => true, 'default_branch' => 'main',
            'html_url' => 'https://github.com/GrupoRyR/portafolio', 'branches' => ['main', 'gia-12-filtro'],
            'language' => 'PHP', 'pushed_at' => '2026-10-02 15:50:00',
        ])->linkTo($product, 'main', linkedBy: $this->admin);
        GitHubRepository::factory()->create([
            'full_name' => 'GrupoRyR/visualizador-cartera', 'name' => 'visualizador-cartera', 'private' => false,
            'html_url' => 'https://github.com/GrupoRyR/visualizador-cartera', 'branches' => null, 'default_branch' => 'develop',
            'language' => null, 'pushed_at' => null,
        ])->linkTo($project, 'develop', linkedBy: $this->admin);
        GitHubRepository::factory()->create([
            'full_name' => 'GrupoRyR/robotcamara', 'name' => 'robotcamara', 'private' => true,
            'html_url' => 'https://github.com/GrupoRyR/robotcamara', 'branches' => ['main', 'develop'],
            'language' => 'Python', 'pushed_at' => '2026-10-05 11:00:00',
        ]);

        $this->event(['event' => 'issues', 'status' => 'processed', 'received_at' => '2026-10-07 09:36:00']);
        $this->event(['event' => 'push', 'status' => 'ignored', 'received_at' => '2026-10-03 08:00:00']);
        $timeout = $this->event(['event' => 'workflow_run', 'repository' => 'GrupoRyR/visualizador-cartera', 'status' => 'failed',
            'error' => 'Se agotó el tiempo al procesarlo.', 'error_code' => 'timeout', 'received_at' => '2026-10-07 06:12:00']);
        $notLinked = $this->event(['event' => 'release', 'repository' => 'GrupoRyR/robotcamara', 'status' => 'failed',
            'error' => 'No está vinculado a ningún producto.', 'error_code' => 'repo_not_linked', 'received_at' => '2026-10-05 17:40:00']);
        $this->event(['event' => 'release', 'repository' => 'GrupoRyR/otro', 'status' => 'failed',
            'error' => 'No está vinculado a ningún producto.', 'error_code' => 'repo_not_linked', 'received_at' => '2026-10-04 10:00:00']);
        // Last month: listed while failed, not counted in October.
        $this->event(['event' => 'issues', 'status' => 'processed', 'received_at' => '2026-09-30 20:00:00']);
        $this->event(['event' => 'pull_request', 'status' => 'pending', 'received_at' => '2026-10-20 09:59:00']);

        $this->actingAs($this->admin)
            ->get(route('admin.integrations.github'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Integrations/GitHub')
                ->where('connection.connected', true)
                ->where('connection.organization', 'GrupoRyR')
                ->where('connection.installed_repos_count', 3)
                ->where('connection.private_key_configured', true)
                ->where('connection.manage_url', 'https://github.com/organizations/GrupoRyR/settings/installations')
                ->where('connection.webhook.configured', true)
                ->where('connection.webhook.last_delivery_at', '2026-10-20T09:59:00-05:00')
                ->where('install_url', self::INSTALL_URL)
                ->has('repos', 3)
                ->where('repos.0', [
                    'full_name' => 'GrupoRyR/portafolio',
                    'name' => 'portafolio',
                    'url' => 'https://github.com/GrupoRyR/portafolio',
                    'private' => true,
                    'default_branch' => 'main',
                    'branches' => ['main', 'gia-12-filtro'],
                    'language' => 'PHP',
                    'pushed_at' => '2026-10-02T15:50:00-05:00',
                    'linked_to' => ['kind' => 'product', 'key' => 'portafolio', 'name' => 'Portafolio de productos'],
                ])
                ->where('repos.1.full_name', 'GrupoRyR/robotcamara')
                ->where('repos.1.linked_to', null)
                ->where('repos.2', [
                    'full_name' => 'GrupoRyR/visualizador-cartera',
                    'name' => 'visualizador-cartera',
                    'url' => 'https://github.com/GrupoRyR/visualizador-cartera',
                    'private' => false,
                    'default_branch' => 'develop',
                    'branches' => ['develop'],
                    'language' => null,
                    'pushed_at' => null,
                    'linked_to' => ['kind' => 'project', 'key' => $project->key, 'name' => 'Visualizador de cartera'],
                ])
                ->where('stats', ['month' => 'octubre', 'received' => 6, 'processed' => 1, 'failed' => 3])
                ->has('failed_events', 3)
                ->where('failed_events.0', [
                    'id' => $timeout->id,
                    'type' => 'workflow_run',
                    'repo' => 'GrupoRyR/visualizador-cartera',
                    'received_at' => '2026-10-07T06:12:00-05:00',
                    'status' => 'failed',
                    'error' => 'Se agotó el tiempo al procesarlo.',
                    'error_code' => 'timeout',
                    'fix_product_slug' => null,
                ])
                ->where('failed_events.1.id', $notLinked->id)
                ->where('failed_events.1.fix_product_slug', $robot->slug)
                ->where('failed_events.2.fix_product_slug', null)
                ->where('now', '2026-10-20T10:00:00-05:00'));
    }

    public function test_props_never_contain_secrets(): void
    {
        $this->connect();
        file_put_contents($this->keyPath, '-----BEGIN PRIVATE KEY----- super-secret-key-material');

        $content = $this->actingAs($this->admin)->get(route('admin.integrations.github'))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $content);
        $this->assertStringNotContainsString('super-secret-key-material', $content);
        $this->assertStringNotContainsString($this->keyPath, $content);
        Http::assertNothingSent();
    }

    public function test_retry_queues_a_failed_event_again(): void
    {
        Bus::fake();
        $failed = $this->event(['status' => 'failed', 'error' => 'Se agotó el tiempo al procesarlo.', 'error_code' => 'timeout', 'attempts' => 1]);

        $this->actingAs($this->admin)
            ->from(route('admin.integrations.github'))
            ->post(route('admin.integrations.github.events.retry', $failed))
            ->assertRedirect(route('admin.integrations.github'))
            ->assertSessionHas('success', 'Evento enviado a reprocesar.');

        $failed->refresh();
        $this->assertSame('pending', $failed->status);
        $this->assertNull($failed->error);
        $this->assertNull($failed->error_code);
        Bus::assertDispatched(ProcessGitHubEvent::class, fn (ProcessGitHubEvent $job) => $job->eventId === $failed->id);

        $activity = Activity::query()->where('event', 'github_event_retried')->sole();
        $this->assertSame('integrations', $activity->log_name);
        $this->assertTrue($activity->causer->is($this->admin));
        $this->assertTrue($activity->subject->is($failed));
    }

    public function test_only_failed_events_can_be_retried(): void
    {
        Bus::fake();
        $processed = $this->event(['status' => 'processed']);

        $this->actingAs($this->admin)
            ->post(route('admin.integrations.github.events.retry', $processed))
            ->assertSessionHas('error', 'Este evento ya no está fallido.');

        $this->assertSame('processed', $processed->fresh()->status);
        Bus::assertNotDispatched(ProcessGitHubEvent::class);
    }

    public function test_disconnect_keeps_events_and_links(): void
    {
        $this->connect();
        $product = AiProduct::factory()->create();
        $repo = GitHubRepository::factory()->create();
        $repo->linkTo($product, 'main', linkedBy: $this->admin);
        $event = $this->event(['status' => 'processed']);

        $this->actingAs($this->admin)
            ->from(route('admin.integrations.github'))
            ->delete(route('admin.integrations.github.disconnect'))
            ->assertRedirect(route('admin.integrations.github'))
            ->assertSessionHas('success', 'GitHub quedó desconectado. Los eventos y los vínculos se conservan.');

        $this->assertNull($this->settings()->get('github.installation_id'));
        $this->assertSame('GrupoRyR', $this->settings()->get('github.organization'));
        $this->assertTrue($repo->fresh()->linkable->is($product));
        $this->assertModelExists($event);

        $activity = Activity::query()->where('event', 'github_disconnected')->sole();
        $this->assertSame('integrations', $activity->log_name);
        $this->assertSame(['installation_id' => 42], $activity->properties['old']);
        $this->assertContains('github_disconnected', config('gestor.audit.sensitive_events'));

        $this->actingAs($this->admin)
            ->get(route('admin.integrations.github'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connection.connected', false)
                ->where('connection.installed_repos_count', 0)
                ->where('repos', [])
                ->etc());
    }

    public function test_actions_require_ai_github_manage(): void
    {
        Bus::fake();
        $failed = $this->event(['status' => 'failed']);
        $this->connect();

        $settingsOnly = tap(User::factory()->microsoft()->create(['company_id' => $this->segurex->id]))
            ->givePermissionTo(['admin.access', 'settings.manage']);

        $this->actingAs($settingsOnly)->post(route('admin.integrations.github.events.retry', $failed))->assertForbidden();
        $this->actingAs($settingsOnly)->delete(route('admin.integrations.github.disconnect'))->assertForbidden();

        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame(42, $this->settings()->get('github.installation_id'));
        Bus::assertNothingDispatched();
    }

    public function test_the_integrations_card_reflects_the_connection(): void
    {
        $this->connect();
        GitHubRepository::factory()->count(2)->create();

        $this->actingAs($this->admin)
            ->get(route('admin.integrations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('github', [
                    'status' => 'connected',
                    'organization' => 'GrupoRyR',
                    'install_url' => self::INSTALL_URL,
                    'repositories_count' => 2,
                ])
                ->etc());
    }
}
