<?php

namespace Tests\Feature\GitHub;

use App\Enums\Ai\ProductVersionSource;
use App\Enums\Ai\RequestStatus;
use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\AiProject;
use App\Models\AiTask;
use App\Models\GitHubRepository;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Ai\Knowledge\KnowledgeTestCase;

/**
 * `Ai/Products/Show.github` (types/pages/ai-github.ts `ProductGitHub`),
 * built from the linked repository and its processed webhook events.
 */
class ProductGitHubTest extends KnowledgeTestCase
{
    use BuildsGitHubWork;

    private AiProduct $portafolio;

    private GitHubRepository $repo;

    private AiProject $p;

    private AiTask $t3;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(9, 40));
        $this->connectGitHub();

        $this->portafolio = $this->product([
            'slug' => 'portafolio', 'name' => 'Portafolio', 'hosting' => 'GoDaddy', 'url' => 'https://portafolio.example.com',
        ]);
        $this->repo = $this->githubRepo('portafolio', ['github_id' => 501]);
        $this->repo->linkTo($this->portafolio, 'main', $this->jhon);

        $this->p = $this->project([
            'name' => 'Portafolio · zonas', 'status' => RequestStatus::InDevelopment, 'product_id' => $this->portafolio->id,
            'repository_url' => 'https://github.com/GrupoRyR/portafolio',
        ]);
        $request = $this->request(['key' => 'GIA-139', 'status' => RequestStatus::InDevelopment, 'product_id' => $this->portafolio->id]);
        $this->p->requests()->attach($request->id);
        $this->task($this->p, 'todo', ['title' => 'Tipos de asesor']);
        $this->task($this->p, 'todo', ['title' => 'Consulta SAP']);
        $this->t3 = $this->task($this->p, 'in_progress', ['title' => 'Endpoint de zonas']);

        $this->superAdmin->forceFill(['github_login' => 'leoh'])->save();
        $this->jhon->forceFill(['github_login' => 'jhonc'])->save();
    }

    private function deliverActivity(): void
    {
        $this->deliver('pull_request', $this->fixture('pull_request'));
        $this->deliver('pull_request', $this->fixture('pull_request', [
            'action' => 'closed',
            'number' => 40,
            'pull_request' => [
                'number' => 40, 'html_url' => 'https://github.com/GrupoRyR/portafolio/pull/40', 'state' => 'closed',
                'title' => 'GIA-139 zonas desde la tabla de asesores', 'body' => null,
                'head' => ['ref' => 'gia-139-zonas-tabla'], 'commits' => 2,
                'created_at' => '2026-10-02T20:00:00Z', 'closed_at' => '2026-10-03T17:00:00Z',
            ],
        ]));
        $this->deliver('workflow_run', $this->fixture('workflow_run'));
        $this->deliver('workflow_run', $this->fixture('workflow_run', ['workflow_run' => [
            'head_branch' => 'main', 'head_sha' => 'a1b2c3d000', 'updated_at' => '2026-10-07T11:40:00Z',
            'html_url' => 'https://github.com/GrupoRyR/portafolio/actions/runs/9002', 'pull_requests' => [],
        ]]));
        $this->deliver('push', $this->fixture('push'));
        $this->deliver('issues', $this->fixture('issues'));
        $this->deliver('issues', $this->fixture('issues', [
            'action' => 'opened',
            'issue' => [
                'number' => 57, 'html_url' => 'https://github.com/GrupoRyR/portafolio/issues/57', 'state' => 'open',
                'title' => 'GIA-139 · T4 Consulta de cartera', 'body' => 'Creado desde Gestor TI', 'user' => ['login' => 'jhonc'],
                'labels' => [['name' => 'gestor-ia'], ['name' => 'sap']], 'assignee' => ['login' => 'jhonc'],
                'created_at' => '2026-10-07T14:38:00Z', 'closed_at' => null,
            ],
        ]));
    }

    public function test_product_page_shows_the_github_activity_of_its_repository(): void
    {
        $this->deliverActivity();
        $version = AiProductVersion::factory()->create([
            'ai_product_id' => $this->portafolio->id, 'version' => '1.8.2', 'tag' => 'v1.8.2', 'summary' => 'Buscador por referencia',
            'source' => ProductVersionSource::GithubRelease, 'published_at' => '2026-10-02 15:58:00', 'released_at' => '2026-10-02',
            'release_url' => 'https://github.com/GrupoRyR/portafolio/releases/tag/v1.8.2', 'github_repository_id' => $this->repo->id,
        ]);
        $version->requests()->attach($this->p->requests()->first()->id);
        AiProductVersion::factory()->create(['ai_product_id' => $this->portafolio->id, 'version' => '1.0.0', 'tag' => null]);

        $this->actingAs($this->jhon)
            ->get(route('ai.products.show', $this->portafolio))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('repo_link', null)
                ->where('github.link.repo.full_name', 'GrupoRyR/portafolio')
                ->where('github.link.repo.linked_to', ['kind' => 'product', 'key' => 'portafolio', 'name' => 'Portafolio'])
                ->where('github.link.default_branch', 'main')
                ->where('github.link.read_docs', true)
                ->where('github.link.receive_events', true)
                ->where('github.link.linked_by', 'Jhon Castro')
                ->where('github.project', ['key' => $this->p->key, 'name' => 'Portafolio · zonas'])
                ->where('github.ci_main', [
                    'status' => 'passing', 'branch' => 'main', 'commit_sha' => 'a1b2c3d', 'run_at' => '2026-10-07T06:40:00-05:00',
                    'summary' => 'Pruebas', 'url' => 'https://github.com/GrupoRyR/portafolio/actions/runs/9002',
                ])
                ->where('github.deploy', [
                    'provider' => 'GoDaddy', 'branch' => 'main', 'commit_sha' => 'c0ffee1', 'deployed_at' => '2026-10-07T08:38:00-05:00',
                    'trigger' => 'push', 'url' => 'https://portafolio.example.com',
                ])
                ->where('github.commits_total', 2)
                ->where('github.commits_authors', 2)
                ->where('github.commits_period', 'jue 1 a mié 7 oct')
                ->where('github.commits.0', [
                    'sha' => 'c0ffee1',
                    'message' => 'Merge pull request #41 from GrupoRyR/gia-139-t3-endpoint-zonas',
                    'author' => ['name' => 'Leonardo Herrera', 'login' => '@leoh'],
                    'committed_at' => '2026-10-07T08:38:00-05:00',
                    'url' => 'https://github.com/GrupoRyR/portafolio/commit/c0ffee1234567890abcdef1234567890abcdef12',
                ])
                ->has('github.open_pull_requests', 1)
                ->where('github.open_pull_requests.0', [
                    'number' => 41,
                    'title' => 'GIA-139 · T3 Endpoint de zonas',
                    'state' => 'open',
                    'ci' => 'passing',
                    'commits' => 3,
                    'author' => ['name' => 'Leonardo Herrera', 'login' => '@leoh'],
                    'branch' => 'gia-139-t3-endpoint-zonas',
                    'base_branch' => 'main',
                    'linked' => ['request_key' => 'GIA-139', 'project_key' => $this->p->key, 'task_key' => 'T3', 'task_title' => 'Endpoint de zonas'],
                    'url' => 'https://github.com/GrupoRyR/portafolio/pull/41',
                    'opened_at' => '2026-10-07T08:10:00-05:00',
                    'merged_at' => null,
                    'closed_at' => null,
                ])
                ->where('github.last_closed_pull_request.number', 40)
                ->where('github.last_closed_pull_request.state', 'closed')
                ->where('github.last_closed_pull_request.linked', ['request_key' => 'GIA-139', 'project_key' => null, 'task_key' => null, 'task_title' => null])
                ->has('github.issues', 2)
                ->where('github.issues.0', [
                    'number' => 57,
                    'title' => 'GIA-139 · T4 Consulta de cartera',
                    'state' => 'open',
                    'labels' => ['gestor-ia', 'sap'],
                    'assignee' => ['name' => 'Jhon Castro', 'login' => '@jhonc'],
                    'created_from_gestor' => true,
                    'created_by' => ['name' => 'Jhon Castro', 'login' => '@jhonc'],
                    'created_at' => '2026-10-07T09:38:00-05:00',
                    'closed_at' => null,
                    'closed_by_pr' => null,
                    'linked' => ['request_key' => 'GIA-139', 'project_key' => null, 'task_key' => null, 'task_title' => null],
                    'url' => 'https://github.com/GrupoRyR/portafolio/issues/57',
                ])
                ->where('github.issues.1.number', 12)
                ->where('github.issues.1.state', 'closed')
                ->where('github.releases', [[
                    'tag' => 'v1.8.2',
                    'summary' => 'Buscador por referencia',
                    'published_at' => '2026-10-02T15:58:00-05:00',
                    'request_keys' => ['GIA-139'],
                    'notified_count' => 0,
                    'url' => 'https://github.com/GrupoRyR/portafolio/releases/tag/v1.8.2',
                ]])
                ->where('github.planned_release', null)
            );
    }

    public function test_quiet_repository_and_viewers_without_github_management(): void
    {
        $this->actingAs($this->itWithoutCosts())
            ->get(route('ai.products.show', $this->portafolio))
            ->assertInertia(fn (Assert $page) => $page
                // GitHub data shows to `ai.manage`; the link dialog needs `ai.github.manage`.
                ->where('repo_link', null)
                ->where('github.ci_main', ['status' => 'none', 'branch' => 'main', 'commit_sha' => null, 'run_at' => null, 'summary' => null, 'url' => null])
                ->where('github.deploy', null)
                ->where('github.commits', [])
                ->where('github.commits_total', 0)
                ->where('github.commits_authors', 0)
                ->where('github.open_pull_requests', [])
                ->where('github.last_closed_pull_request', null)
                ->where('github.issues', [])
                ->where('github.releases', [])
            );

        $this->actingAs($this->paola)->get(route('ai.products.show', $this->portafolio))->assertForbidden();
    }
}
