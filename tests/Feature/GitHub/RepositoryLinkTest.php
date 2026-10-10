<?php

namespace Tests\Feature\GitHub;

use App\Jobs\ProcessGitHubEvent;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\GitHubWebhookEvent;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Ai\Knowledge\KnowledgeTestCase;

/**
 * "Vincular repositorio" (`ai.products.repository.store`,
 * `ai.projects.repository.store`) and the `repo_link` setup of the product
 * and project pages (types/pages/ai-github.ts `RepoLinkSetup`).
 */
class RepositoryLinkTest extends KnowledgeTestCase
{
    use BuildsGitHubWork;

    private AiProduct $camaras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(9, 40));
        $this->connectGitHub();
        $this->camaras = $this->product(['slug' => 'camaras', 'name' => 'Cámaras', 'repository' => 'GrupoRyR/robotcamara']);
    }

    private function failedRelease(string $repo = 'GrupoRyR/robotcamara', string $receivedAt = '2026-10-05 17:40:00'): GitHubWebhookEvent
    {
        return GitHubWebhookEvent::factory()->create([
            'event' => 'release',
            'action' => 'published',
            'repository' => $repo,
            'payload' => ['action' => 'published'],
            'status' => GitHubWebhookEvent::STATUS_FAILED,
            'error' => 'El repositorio no está vinculado a un producto ni a un proyecto.',
            'error_code' => GitHubWebhookEvent::ERROR_REPO_NOT_LINKED,
            'received_at' => $receivedAt,
        ]);
    }

    public function test_product_page_sends_the_link_dialog_only_to_github_managers(): void
    {
        $robot = $this->githubRepo('robotcamara', ['branches' => ['main', 'develop'], 'language' => 'Python']);
        $linked = $this->githubRepo('portafolio');
        $linked->linkTo($this->product(['slug' => 'portafolio', 'name' => 'Portafolio']), 'main', $this->jhon);
        $this->failedRelease();

        $this->actingAs($this->jhon)
            ->get(route('ai.products.show', $this->camaras))
            ->assertInertia(fn (Assert $page) => $page
                ->where('github', null)
                ->where('repo_link.target', ['kind' => 'product', 'key' => 'camaras', 'name' => 'Cámaras', 'product_key' => null])
                ->where('repo_link.organization', 'GrupoRyR')
                ->where('repo_link.open', false)
                ->where('repo_link.defaults', ['repo' => 'GrupoRyR/robotcamara', 'branch' => 'main', 'read_docs' => true, 'receive_events' => true])
                ->where('repo_link.pending_note', 'El release que falló el 5 oct se reprocesa solo al vincular.')
                // Free repositories first.
                ->where('repo_link.repos.0', [
                    'full_name' => 'GrupoRyR/robotcamara',
                    'name' => 'robotcamara',
                    'url' => 'https://github.com/GrupoRyR/robotcamara',
                    'private' => true,
                    'default_branch' => 'main',
                    'branches' => ['main', 'develop'],
                    'language' => 'Python',
                    'pushed_at' => $robot->pushed_at->toIso8601String(),
                    'linked_to' => null,
                ])
                ->where('repo_link.repos.1.linked_to', ['kind' => 'product', 'key' => 'portafolio', 'name' => 'Portafolio'])
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.products.show', [$this->camaras, 'vincular' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('repo_link.open', true));

        // IT without `ai.github.manage`: no dialog.
        $this->actingAs($this->itWithoutCosts())
            ->get(route('ai.products.show', $this->camaras))
            ->assertInertia(fn (Assert $page) => $page->where('repo_link', null)->where('github', null));
    }

    public function test_linking_a_product_reprocesses_the_events_that_waited_for_it(): void
    {
        Queue::fake();
        $repo = $this->githubRepo('robotcamara', ['branches' => ['main', 'develop']]);
        $waiting = $this->failedRelease();
        $other = $this->failedRelease('GrupoRyR/otro');

        $this->actingAs($this->jhon)
            ->post(route('ai.products.repository.store', $this->camaras), [
                'repo' => 'GrupoRyR/robotcamara', 'branch' => 'develop', 'read_docs' => false, 'receive_events' => true,
            ])
            ->assertRedirect(route('ai.products.show', $this->camaras))
            ->assertSessionHas('success', 'Repositorio vinculado. 1 evento pendiente se está reprocesando.');

        $repo->refresh();
        $this->assertTrue($repo->linkable->is($this->camaras));
        $this->assertSame('develop', $repo->branch);
        $this->assertFalse($repo->read_docs);
        $this->assertTrue($repo->receive_events);
        $this->assertSame($this->jhon->id, $repo->linked_by_id);
        $this->assertSame('GrupoRyR/robotcamara', $this->camaras->fresh()->repository);

        $this->assertSame(GitHubWebhookEvent::STATUS_PENDING, $waiting->fresh()->status);
        $this->assertNull($waiting->fresh()->error_code);
        $this->assertSame(GitHubWebhookEvent::STATUS_FAILED, $other->fresh()->status);
        Queue::assertPushed(ProcessGitHubEvent::class, fn (ProcessGitHubEvent $job) => $job->eventId === $waiting->id);
        Queue::assertPushed(ProcessGitHubEvent::class, 1);

        // Once linked, the page shows the activity and no dialog.
        $this->actingAs($this->jhon)
            ->get(route('ai.products.show', $this->camaras))
            ->assertInertia(fn (Assert $page) => $page
                ->where('repo_link', null)
                ->where('github.link.repo.full_name', 'GrupoRyR/robotcamara')
                ->where('github.link.default_branch', 'develop')
                ->where('github.link.read_docs', false)
                ->where('github.link.linked_by', 'Jhon Castro')
            );
    }

    public function test_link_validation_keeps_the_dialog_open_with_errors(): void
    {
        $this->githubRepo('robotcamara');
        $this->githubRepo('portafolio')->linkTo($this->product(['slug' => 'portafolio', 'name' => 'Portafolio']), 'main', $this->jhon);
        $store = route('ai.products.repository.store', $this->camaras);

        $this->actingAs($this->jhon)->from(route('ai.products.show', $this->camaras))->post($store, [])
            ->assertSessionHasErrors(['repo' => 'Elige el repositorio.', 'branch' => 'Elige la rama principal.']);

        $this->actingAs($this->jhon)->post($store, ['repo' => 'GrupoRyR/nada', 'branch' => 'main'])
            ->assertSessionHasErrors(['repo' => 'Ese repositorio no está en la organización o la app de GitHub no tiene acceso a él.']);

        $this->actingAs($this->jhon)->post($store, ['repo' => 'GrupoRyR/portafolio', 'branch' => 'main'])
            ->assertSessionHasErrors(['repo' => 'Ese repositorio ya está vinculado a Portafolio.']);

        $this->actingAs($this->jhon)
            ->from(route('ai.products.show', $this->camaras))
            ->post($store, ['repo' => 'GrupoRyR/robotcamara', 'branch' => 'gh-pages', 'read_docs' => true, 'receive_events' => false])
            ->assertSessionHasErrors(['branch' => 'Esa rama no existe en el repositorio.']);

        // Back on the page: the dialog reopens with the old input.
        $this->actingAs($this->jhon)
            ->from(route('ai.products.show', $this->camaras))
            ->followingRedirects()
            ->post($store, ['repo' => 'GrupoRyR/robotcamara', 'branch' => 'gh-pages', 'read_docs' => true, 'receive_events' => false])
            ->assertInertia(fn (Assert $page) => $page
                ->where('repo_link.open', true)
                ->where('repo_link.defaults', ['repo' => 'GrupoRyR/robotcamara', 'branch' => 'gh-pages', 'read_docs' => true, 'receive_events' => false])
            );

        $this->assertNull($this->camaras->fresh()->githubRepository);
    }

    public function test_only_github_managers_can_link(): void
    {
        $this->githubRepo('robotcamara');
        $it = $this->itWithoutCosts();
        $project = $this->project();
        $payload = ['repo' => 'GrupoRyR/robotcamara', 'branch' => 'main', 'read_docs' => true, 'receive_events' => true];

        $this->actingAs($it)->post(route('ai.products.repository.store', $this->camaras), $payload)->assertForbidden();
        $this->actingAs($this->paola)->post(route('ai.products.repository.store', $this->camaras), $payload)->assertForbidden();
        $this->actingAs($it)->post(route('ai.projects.repository.store', $project), $payload)->assertForbidden();

        $this->assertNull($this->camaras->fresh()->githubRepository);
    }

    public function test_a_project_links_its_own_repository_or_reuses_its_products(): void
    {
        $toma = $this->product(['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP']);
        $productRepo = $this->githubRepo('toma-pedidos', ['branches' => ['main', 'descuentos']]);
        $productRepo->linkTo($toma, 'main', $this->jhon);
        $own = $this->githubRepo('descuentos');

        $p6 = $this->project(['name' => 'Toma Pedidos · descuentos por volumen', 'product_id' => $toma->id]);
        $p8 = $this->project(['name' => 'Integración nueva']);

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.show', $p6))
            ->assertInertia(fn (Assert $page) => $page
                ->where('repo_link.target', ['kind' => 'project', 'key' => $p6->key, 'name' => 'Toma Pedidos · descuentos por volumen', 'product_key' => 'toma-pedidos'])
                ->where('repo_link.defaults.repo', 'GrupoRyR/toma-pedidos')
                ->where('repo_link.pending_note', null)
                ->where('repo_create', ['organization' => 'GrupoRyR', 'default_name' => 'toma-pedidos-descuentos-por-volumen'])
            );

        // Reusing the product's repository keeps the product link.
        $this->actingAs($this->jhon)
            ->post(route('ai.projects.repository.store', $p6), ['repo' => 'GrupoRyR/toma-pedidos', 'branch' => 'descuentos', 'read_docs' => true, 'receive_events' => true])
            ->assertRedirect(route('ai.projects.show', $p6))
            ->assertSessionHas('success', 'Repositorio vinculado.');

        $this->assertTrue($productRepo->fresh()->linkable->is($toma));
        $this->assertSame('https://github.com/GrupoRyR/toma-pedidos', $p6->fresh()->repository_url);
        $this->assertSame('descuentos', $p6->fresh()->repository_branch);
        $this->assertSame('vinculó el repositorio GrupoRyR/toma-pedidos', $p6->activities()->latest('id')->first()->text);

        // Another project cannot take the product's repository.
        $this->actingAs($this->jhon)
            ->post(route('ai.projects.repository.store', $p8), ['repo' => 'GrupoRyR/toma-pedidos', 'branch' => 'main'])
            ->assertSessionHasErrors(['repo' => 'Ese repositorio ya está vinculado a Toma Pedidos SAP.']);

        $this->actingAs($this->jhon)
            ->post(route('ai.projects.repository.store', $p8), ['repo' => 'GrupoRyR/descuentos', 'branch' => 'main', 'read_docs' => true, 'receive_events' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($own->fresh()->linkable->is($p8));
        $this->assertSame(AiProject::class, $own->fresh()->linkable_type);

        // With a repository the summary sends neither dialog.
        $this->actingAs($this->jhon)
            ->get(route('ai.projects.show', $p8))
            ->assertInertia(fn (Assert $page) => $page->where('repo_link', null)->where('repo_create', null));

        $this->actingAs($this->itWithoutCosts())
            ->get(route('ai.projects.show', $this->project()))
            ->assertInertia(fn (Assert $page) => $page->where('repo_link', null)->where('repo_create', null));
    }
}
