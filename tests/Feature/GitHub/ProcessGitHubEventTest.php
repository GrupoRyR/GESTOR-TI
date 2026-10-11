<?php

namespace Tests\Feature\GitHub;

use App\Enums\Ai\ProductVersionSource;
use App\Enums\Ai\RequestStatus;
use App\Jobs\ProcessGitHubEvent;
use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiTask;
use App\Models\GitHubRepository;
use App\Models\GitHubWebhookEvent;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Ai\Projects\ProjectsTestCase;

/**
 * App\Jobs\ProcessGitHubEvent with the compact payloads of
 * tests/Fixtures/github: issues, pull requests (board moves), CI, pushes to
 * the linked branch, releases (product versions) and installations.
 */
class ProcessGitHubEventTest extends ProjectsTestCase
{
    use GeneratesAppKey;

    private AiProduct $product;

    private GitHubRepository $repo;

    private AiProject $project;

    private AiRequest $request139;

    private AiTask $t1;

    private AiTask $t3;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->product = AiProduct::factory()->create(['name' => 'Portafolio', 'current_version' => '1.8.2']);
        $this->repo = GitHubRepository::factory()->create([
            'github_id' => 501, 'full_name' => 'GrupoRyR/portafolio', 'name' => 'portafolio', 'html_url' => 'https://github.com/GrupoRyR/portafolio',
        ]);
        $this->repo->linkTo($this->product, 'main', linkedBy: $this->jhon);

        $this->project = $this->project(['status' => RequestStatus::InDevelopment, 'product_id' => $this->product->id]);
        $this->request139 = $this->request(['key' => 'GIA-139', 'status' => RequestStatus::InDevelopment, 'product_id' => $this->product->id]);
        $this->project->requests()->attach($this->request139->id);

        $this->t1 = $this->task($this->project, 'todo', ['title' => 'Tipos de asesor']);
        $this->task($this->project, 'todo', ['title' => 'Consulta SAP']);
        $this->t3 = $this->task($this->project, 'in_progress', ['title' => 'Endpoint de zonas']);

        $this->superAdmin->forceFill(['github_login' => 'leoh'])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $fixture, array $overrides = []): array
    {
        $payload = json_decode((string) file_get_contents(base_path("tests/Fixtures/github/{$fixture}.json")), true);

        return array_replace_recursive($payload, $overrides);
    }

    private function process(string $event, array $payload): GitHubWebhookEvent
    {
        $stored = GitHubWebhookEvent::factory()->create([
            'event' => $event,
            'action' => $payload['action'] ?? null,
            'repository' => $payload['repository']['full_name'] ?? null,
            'payload' => $payload,
            'status' => GitHubWebhookEvent::STATUS_PENDING,
        ]);

        ProcessGitHubEvent::dispatchSync($stored->id);

        return $stored->fresh();
    }

    private function columnOf(AiTask $task): string
    {
        return $task->fresh()->column->key;
    }

    // ---- pull requests ------------------------------------------------------

    public function test_an_opened_pull_request_links_the_task_and_moves_it_to_review(): void
    {
        $event = $this->process('pull_request', $this->payload('pull_request'));

        $this->assertSame('processed', $event->status);
        $this->assertSame(['GIA-139'], $event->request_keys);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->processed_at);

        $t3 = $this->t3->fresh();
        $this->assertSame('review', $t3->column->key);
        $this->assertSame(41, $t3->github_pr_number);
        $this->assertSame('https://github.com/GrupoRyR/portafolio/pull/41', $t3->github_pr_url);
        $this->assertSame('open', $t3->github_pr_state);
        $this->assertSame('todo', $this->columnOf($this->t1));

        $this->assertDatabaseHas('ai_project_activities', [
            'ai_project_id' => $this->project->id, 'kind' => 'github', 'user_id' => $this->superAdmin->id,
            'text' => 'PR #41 abierto: GIA-139 · T3 Endpoint de zonas',
        ]);
        $this->assertDatabaseHas('ai_project_activities', [
            'ai_project_id' => $this->project->id, 'kind' => 'task', 'text' => 'movió T3 Endpoint de zonas a En revisión',
        ]);
    }

    public function test_the_task_is_found_through_the_issue_the_pull_request_closes(): void
    {
        $this->t1->forceFill(['github_issue_number' => 12, 'github_issue_url' => 'https://github.com/GrupoRyR/portafolio/issues/12'])->save();

        $this->process('pull_request', $this->payload('pull_request', ['pull_request' => [
            'title' => 'GIA-139 tipos', 'head' => ['ref' => 'gia-139-tipos'], 'body' => 'Fixes #12',
        ]]));

        $this->assertSame('review', $this->columnOf($this->t1));
        $this->assertSame('in_progress', $this->columnOf($this->t3));
    }

    public function test_a_draft_pull_request_links_without_moving_and_ready_for_review_moves(): void
    {
        $this->process('pull_request', $this->payload('pull_request', ['pull_request' => ['draft' => true]]));

        $this->assertSame(41, $this->t3->fresh()->github_pr_number);
        $this->assertSame('in_progress', $this->columnOf($this->t3));

        $this->process('pull_request', $this->payload('pull_request', ['action' => 'ready_for_review']));

        $this->assertSame('review', $this->columnOf($this->t3));
    }

    public function test_a_merged_pull_request_moves_the_task_to_done(): void
    {
        $this->freezeTime();

        $event = $this->process('pull_request', $this->payload('pull_request', [
            'action' => 'closed',
            'pull_request' => ['state' => 'closed', 'merged' => true, 'merged_at' => '2026-10-07T13:38:00Z', 'merged_by' => ['login' => 'leoh']],
        ]));

        $this->assertSame('processed', $event->status);
        $t3 = $this->t3->fresh();
        $this->assertSame('done', $t3->column->key);
        $this->assertSame('merged', $t3->github_pr_state);
        $this->assertNotNull($t3->completed_at);
        $this->assertDatabaseHas('ai_project_activities', ['kind' => 'github', 'text' => 'PR #41 fusionado: GIA-139 · T3 Endpoint de zonas']);
    }

    public function test_a_pull_request_closed_without_merge_only_updates_the_link(): void
    {
        $this->process('pull_request', $this->payload('pull_request', ['action' => 'closed', 'pull_request' => ['state' => 'closed']]));

        $t3 = $this->t3->fresh();
        $this->assertSame('closed', $t3->github_pr_state);
        $this->assertSame('in_progress', $t3->column->key);
    }

    public function test_a_done_task_does_not_go_back_to_review(): void
    {
        $this->t3->forceFill(['ai_project_column_id' => $this->project->column('done')->id])->save();

        $this->process('pull_request', $this->payload('pull_request', ['action' => 'reopened']));

        $this->assertSame('done', $this->columnOf($this->t3));
    }

    public function test_events_of_unlinked_repositories_fail_with_repo_not_linked(): void
    {
        GitHubRepository::factory()->create(['github_id' => 777, 'full_name' => 'GrupoRyR/suelto', 'name' => 'suelto']);

        $unlinked = $this->process('pull_request', $this->payload('pull_request', ['repository' => ['id' => 777, 'full_name' => 'GrupoRyR/suelto']]));
        $unknown = $this->process('issues', $this->payload('issues', ['repository' => ['id' => 999, 'full_name' => 'GrupoRyR/desconocido']]));

        foreach ([$unlinked, $unknown] as $event) {
            $this->assertSame('failed', $event->status);
            $this->assertSame('repo_not_linked', $event->error_code);
            $this->assertSame('El repositorio no está vinculado a un producto ni a un proyecto.', $event->error);
        }
        $this->assertSame('in_progress', $this->columnOf($this->t3));
    }

    public function test_events_are_ignored_when_the_link_does_not_receive_events(): void
    {
        $this->repo->forceFill(['receive_events' => false])->save();

        $event = $this->process('pull_request', $this->payload('pull_request'));

        $this->assertSame('ignored', $event->status);
        $this->assertSame('in_progress', $this->columnOf($this->t3));
    }

    public function test_retry_from_the_admin_page_reprocesses_a_failed_event(): void
    {
        $this->repo->forceFill(['linkable_type' => null, 'linkable_id' => null])->save();
        $event = $this->process('pull_request', $this->payload('pull_request'));
        $this->assertSame('repo_not_linked', $event->error_code);

        $this->repo->linkTo($this->product, 'main', linkedBy: $this->jhon);

        $this->actingAs($this->jhon)
            ->post(route('admin.integrations.github.events.retry', $event))
            ->assertSessionHas('success');

        $event->refresh();
        $this->assertSame('processed', $event->status);
        $this->assertNull($event->error_code);
        $this->assertSame(2, $event->attempts);
        $this->assertSame('review', $this->columnOf($this->t3));
    }

    // ---- issues ---------------------------------------------------------------

    public function test_issue_events_store_keys_and_update_the_task_issue_state(): void
    {
        $this->t3->forceFill(['github_issue_number' => 12, 'github_issue_url' => 'https://github.com/GrupoRyR/portafolio/issues/12', 'github_issue_state' => 'open'])->save();
        // Same number in another repository's project is not touched.
        $other = $this->project();
        $foreign = $this->task($other, 'todo', ['github_issue_number' => 12, 'github_issue_url' => 'https://github.com/GrupoRyR/otro/issues/12', 'github_issue_state' => 'open']);

        $event = $this->process('issues', $this->payload('issues'));

        $this->assertSame('processed', $event->status);
        $this->assertSame(['GIA-139'], $event->request_keys);
        $this->assertSame('closed', $this->t3->fresh()->github_issue_state);
        $this->assertSame('open', $foreign->fresh()->github_issue_state);

        $this->process('issues', $this->payload('issues', ['action' => 'reopened', 'issue' => ['state' => 'open']]));
        $this->assertSame('open', $this->t3->fresh()->github_issue_state);
    }

    // ---- CI -------------------------------------------------------------------

    public function test_workflow_runs_store_ci_on_tasks_and_per_branch(): void
    {
        $this->t3->forceFill(['github_pr_number' => 41])->save();

        $this->process('workflow_run', $this->payload('workflow_run'));

        $this->assertSame('passing', $this->t3->fresh()->github_ci);
        $ci = $this->repo->fresh()->ci;
        $this->assertSame([
            'status' => 'passing',
            'branch' => 'gia-139-t3-endpoint-zonas',
            'commit_sha' => 'a1b2c3d',
            'run_at' => '2026-10-07T13:20:00+00:00',
            'summary' => 'Pruebas',
            'url' => 'https://github.com/GrupoRyR/portafolio/actions/runs/9001',
        ], $ci['gia-139-t3-endpoint-zonas']);

        // In progress, then failing on main; an older delivery never overwrites a newer run.
        $this->process('workflow_run', $this->payload('workflow_run', ['action' => 'in_progress', 'workflow_run' => [
            'head_branch' => 'main', 'status' => 'in_progress', 'conclusion' => null, 'updated_at' => '2026-10-07T14:00:00Z', 'pull_requests' => [['number' => 99]],
        ]]));
        $this->assertSame('running', $this->repo->fresh()->ci['main']['status']);

        $this->process('workflow_run', $this->payload('workflow_run', ['workflow_run' => [
            'head_branch' => 'main', 'conclusion' => 'failure', 'updated_at' => '2026-10-07T14:05:00Z', 'pull_requests' => [['number' => 99]],
        ]]));
        $this->process('workflow_run', $this->payload('workflow_run', ['workflow_run' => [
            'head_branch' => 'main', 'conclusion' => 'success', 'updated_at' => '2026-10-07T13:00:00Z', 'pull_requests' => [['number' => 99]],
        ]]));

        $ci = $this->repo->fresh()->ci;
        $this->assertSame('failing', $ci['main']['status']);
        $this->assertSame('passing', $ci['gia-139-t3-endpoint-zonas']['status']);
        $this->assertSame('passing', $this->t3->fresh()->github_ci);
    }

    // ---- push -----------------------------------------------------------------

    public function test_a_push_to_the_linked_branch_stores_the_deploy_and_commits(): void
    {
        $this->superAdmin->forceFill(['github_login' => 'leoh'])->save();

        $event = $this->process('push', $this->payload('push'));

        $this->assertSame('processed', $event->status);
        $this->assertSame(['GIA-139'], $event->request_keys);

        $repo = $this->repo->fresh();
        $this->assertSame([
            'branch' => 'main',
            'commit_sha' => 'c0ffee1',
            'deployed_at' => '2026-10-07T08:38:00-05:00',
            'url' => 'https://github.com/GrupoRyR/portafolio/commit/c0ffee1234567890abcdef1234567890abcdef12',
        ], $repo->last_deploy);
        $this->assertSame(['c0ffee1', '5c4b3a2'], array_column($repo->recent_commits, 'sha'));
        $this->assertSame([
            'sha' => 'c0ffee1',
            'message' => 'Merge pull request #41 from GrupoRyR/gia-139-t3-endpoint-zonas',
            'author_name' => 'Leonardo Herrera',
            'author_login' => 'leoh',
            'committed_at' => '2026-10-07T08:38:00-05:00',
            'url' => 'https://github.com/GrupoRyR/portafolio/commit/c0ffee1234567890abcdef1234567890abcdef12',
        ], $repo->recent_commits[0]);
        $this->assertSame('2026-10-07 13:38:00', $repo->pushed_at->utc()->toDateTimeString());
    }

    public function test_pushes_to_other_branches_are_ignored(): void
    {
        $event = $this->process('push', $this->payload('push', ['ref' => 'refs/heads/gia-139-t3-endpoint-zonas']));

        $this->assertSame('ignored', $event->status);
        $this->assertNull($this->repo->fresh()->last_deploy);
    }

    // ---- releases ---------------------------------------------------------------

    private function mergedPr(int $number, string $title, string $mergedAt): void
    {
        $payload = $this->payload('pull_request', [
            'action' => 'closed', 'number' => $number,
            'pull_request' => ['number' => $number, 'title' => $title, 'body' => '', 'head' => ['ref' => 'feature-'.$number], 'state' => 'closed', 'merged' => true, 'merged_at' => $mergedAt],
        ]);

        GitHubWebhookEvent::factory()->create([
            'event' => 'pull_request', 'action' => 'closed', 'repository' => 'GrupoRyR/portafolio', 'payload' => $payload,
            'status' => GitHubWebhookEvent::STATUS_PROCESSED,
        ]);
    }

    public function test_a_published_release_creates_the_product_version_with_the_requests_since_the_previous_one(): void
    {
        $request112 = $this->request(['key' => 'GIA-112', 'product_id' => $this->product->id]);
        $request150 = $this->request(['key' => 'GIA-150', 'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'requester_id' => $this->derivadosUser->id, 'created_by_id' => $this->derivadosUser->id]);
        $request100 = $this->request(['key' => 'GIA-100']);

        // Previous release (v1.8.2) published on 2026-10-01.
        $this->process('release', $this->payload('release', ['release' => [
            'id' => 7000, 'tag_name' => 'v1.8.2', 'name' => 'v1.8.2', 'body' => 'Sin solicitudes', 'published_at' => '2026-10-01T15:00:00Z',
        ]]));
        $this->mergedPr(30, 'GIA-100 antes del release anterior', '2026-09-30T12:00:00Z');
        $this->mergedPr(41, 'GIA-139 · T3 Endpoint de zonas', '2026-10-07T13:38:00Z');
        $this->mergedPr(44, 'Arreglo de gia-150', '2026-10-08T10:00:00Z');
        $this->mergedPr(50, 'GIA-100 después del release', '2026-10-09T10:00:00Z');

        $event = $this->process('release', $this->payload('release'));

        $this->assertSame('processed', $event->status);
        $this->assertSame(['GIA-139', 'GIA-150', 'GIA-112'], $event->request_keys);

        $version = AiProductVersion::query()->where('version', '1.9.0')->sole();
        $this->assertTrue($version->product->is($this->product));
        $this->assertSame('v1.9.0', $version->tag);
        $this->assertSame(ProductVersionSource::GithubRelease, $version->source);
        $this->assertSame('https://github.com/GrupoRyR/portafolio/releases/tag/v1.9.0', $version->release_url);
        $this->assertSame('v1.9.0 · Filtro por asesor y zona', $version->summary);
        $this->assertSame("## Cambios\n- feat: filtro por asesor y zona (GIA-139) #41\n- fix: totales por rango (GIA-112)", $version->technical_notes);
        $this->assertNull($version->user_notes);
        $this->assertNull($version->notified_at);
        $this->assertSame('jhonc', $version->author_login);
        $this->assertSame(7001, $version->github_release_id);
        $this->assertSame($this->repo->id, $version->github_repository_id);
        $this->assertSame('2026-10-08', $version->released_at->toDateString());
        $this->assertSame('2026-10-08T15:00:00+00:00', $version->published_at->utc()->toIso8601String());

        $included = $version->requests()->orderBy('key')->get()->mapWithKeys(fn (AiRequest $r) => [$r->key => $r->pivot->found_in_pr])->all();
        $this->assertSame(['GIA-112' => null, 'GIA-139' => 41, 'GIA-150' => 44], $included);
        $this->assertFalse($version->requests()->whereKey($request100->id)->exists());

        $this->assertSame('1.9.0', $this->product->fresh()->current_version);
        // D4 notifies requesters after IT confirms the user notes.
        Notification::assertNothingSent();
        $this->assertSame(RequestStatus::Received, $request112->fresh()->status);

        // A redelivery does not duplicate the version nor its requests.
        $this->process('release', $this->payload('release'));
        $this->assertSame(1, AiProductVersion::query()->where('version', '1.9.0')->count());
        $this->assertSame(3, $version->requests()->count());
        $this->assertNotNull($request150);
    }

    public function test_prereleases_and_other_release_actions_are_ignored(): void
    {
        $this->assertSame('ignored', $this->process('release', $this->payload('release', ['release' => ['prerelease' => true]]))->status);
        $this->assertSame('ignored', $this->process('release', $this->payload('release', ['action' => 'created']))->status);
        $this->assertSame(0, AiProductVersion::query()->count());
    }

    public function test_a_release_of_a_project_repository_uses_the_project_product(): void
    {
        $repo = GitHubRepository::factory()->create(['github_id' => 600, 'full_name' => 'GrupoRyR/zonas', 'name' => 'zonas']);
        $repo->linkTo($this->project, 'main', linkedBy: null);

        $event = $this->process('release', $this->payload('release', ['repository' => ['id' => 600, 'full_name' => 'GrupoRyR/zonas']]));
        $this->assertSame('processed', $event->status);
        $this->assertTrue(AiProductVersion::query()->sole()->product->is($this->product));

        $lonely = $this->project();
        $repo->linkTo($lonely, 'main', linkedBy: null);
        $failed = $this->process('release', $this->payload('release', ['release' => ['id' => 7002, 'tag_name' => 'v2.0.0'], 'repository' => ['id' => 600, 'full_name' => 'GrupoRyR/zonas']]));

        $this->assertSame('failed', $failed->status);
        $this->assertSame('no_product', $failed->error_code);
        $this->assertSame('El proyecto vinculado no tiene un producto donde crear la versión.', $failed->error);
    }

    // ---- installation -------------------------------------------------------------

    private function fakeGitHub(): void
    {
        $this->generateAppKey();
        config(['services.github.app_id' => '1']);

        Http::fake([
            'api.github.com/orgs/GrupoRyR/installation' => Http::response(['id' => 42]),
            'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            'api.github.com/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [[
                'id' => 501, 'full_name' => 'GrupoRyR/portafolio', 'name' => 'portafolio', 'private' => true,
                'html_url' => 'https://github.com/GrupoRyR/portafolio', 'default_branch' => 'main', 'language' => 'TypeScript',
            ]]]),
        ]);
    }

    public function test_installation_events_sync_the_repositories(): void
    {
        $this->fakeGitHub();
        app(Settings::class)->set('github.organization', 'GrupoRyR');

        $event = $this->process('installation', $this->payload('installation'));

        $this->assertSame('processed', $event->status);
        $this->assertSame(42, app(Settings::class)->get('github.installation_id'));
        $this->assertSame('TypeScript', $this->repo->fresh()->language);

        $this->assertSame('processed', $this->process('installation_repositories', $this->payload('installation', ['action' => 'added']))->status);

        $deleted = $this->process('installation', $this->payload('installation', ['action' => 'deleted']));
        $this->assertSame('processed', $deleted->status);
        $this->assertNull(app(Settings::class)->get('github.installation_id'));
    }

    public function test_installations_of_another_account_are_ignored(): void
    {
        app(Settings::class)->set('github.organization', 'GrupoRyR');

        $event = $this->process('installation', $this->payload('installation', ['installation' => ['account' => ['login' => 'Otra']]]));

        $this->assertSame('ignored', $event->status);
        $this->assertNull(app(Settings::class)->get('github.installation_id'));
    }

    public function test_unexpected_errors_mark_the_event_failed_and_are_retried_by_the_queue(): void
    {
        $this->generateAppKey();
        config(['services.github.app_id' => '1']);
        app(Settings::class)->set('github.organization', 'GrupoRyR');
        Http::fake(['api.github.com/*' => Http::response(['message' => 'boom'], 500)]);

        $stored = GitHubWebhookEvent::factory()->create([
            'event' => 'installation', 'action' => 'created', 'repository' => null,
            'payload' => $this->payload('installation'), 'status' => GitHubWebhookEvent::STATUS_PENDING,
        ]);

        try {
            ProcessGitHubEvent::dispatchSync($stored->id);
            $this->fail('The job should rethrow so the queue retries it.');
        } catch (\Throwable) {
            // expected
        }

        $stored->refresh();
        $this->assertSame('failed', $stored->status);
        $this->assertSame('github_500', $stored->error_code);
        $this->assertSame('No se pudo procesar el evento. Se reintentará; si sigue fallando, usa "Reprocesar".', $stored->error);
        $this->assertSame(1, $stored->attempts);
    }

    public function test_processed_events_are_not_processed_twice(): void
    {
        $event = $this->process('pull_request', $this->payload('pull_request'));
        $this->t3->forceFill(['ai_project_column_id' => $this->project->column('todo')->id])->save();

        ProcessGitHubEvent::dispatchSync($event->id);

        $this->assertSame(1, $event->fresh()->attempts);
        $this->assertSame('todo', $this->columnOf($this->t3));
    }

    // ---- github:sync ----------------------------------------------------------------

    public function test_the_sync_command_refreshes_the_repositories(): void
    {
        $this->artisan('github:sync')->expectsOutputToContain('no está conectado')->assertFailed();

        $this->fakeGitHub();
        app(Settings::class)->set('github.organization', 'GrupoRyR');

        $this->artisan('github:sync')->expectsOutput('Repositorios sincronizados: 1.')->assertSuccessful();
        $this->assertSame('TypeScript', $this->repo->fresh()->language);
    }
}
