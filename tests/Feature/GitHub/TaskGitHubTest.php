<?php

namespace Tests\Feature\GitHub;

use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Http\Controllers\AI\GitHubController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AcceptanceCriterion;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiRequestComment;
use App\Models\AiTask;
use App\Models\GitHubRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Ai\Projects\ProjectsTestCase;

/**
 * The task GitHub block (`TaskGitHub` on the board drawer and the task
 * page) and "Crear issue en GitHub" (`ai.tasks.issue.store`).
 */
class TaskGitHubTest extends ProjectsTestCase
{
    use BuildsGitHubWork;

    private AiProduct $product;

    private GitHubRepository $repo;

    private AiProject $p;

    private AiRequest $gia139;

    private AiTask $t3;

    private AiTask $t4;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(9, 40));
        $this->connectGitHub();

        $this->product = AiProduct::factory()->create(['name' => 'Portafolio']);
        $this->repo = GitHubRepository::factory()->create([
            'github_id' => 501, 'full_name' => 'GrupoRyR/portafolio', 'name' => 'portafolio', 'html_url' => 'https://github.com/GrupoRyR/portafolio',
        ]);
        $this->repo->linkTo($this->product, 'main', $this->jhon);

        $this->p = $this->project(['status' => RequestStatus::InDevelopment, 'product_id' => $this->product->id]);
        $this->gia139 = $this->request(['key' => 'GIA-139', 'status' => RequestStatus::InDevelopment, 'product_id' => $this->product->id]);
        $this->p->requests()->attach($this->gia139->id);
        AcceptanceCriterion::factory()->create(['ai_request_id' => $this->gia139->id, 'position' => 1, 'text' => 'Filtra por asesor']);

        $this->task($this->p, 'todo', ['title' => 'Tipos de asesor']);
        $this->task($this->p, 'todo', ['title' => 'Consulta SAP']);
        $this->t3 = $this->task($this->p, 'in_progress', ['title' => 'Endpoint de zonas'], [$this->superAdmin]);
        $this->t4 = $this->task($this->p, 'todo', ['title' => 'Consulta de cartera por asesor', 'labels' => ['SAP']], [$this->jhon]);

        $this->superAdmin->forceFill(['github_login' => 'leoh'])->save();
        $this->jhon->forceFill(['github_login' => 'jhonc'])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function issuePayload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'GIA-139 · T4 Consulta de cartera por asesor',
            'body' => "Traer la cartera por asesor.\n\n## Criterios de aceptación\n\n- [ ] Filtra por asesor\n\n---\n\nCreado desde Gestor TI · solicitud GIA-139\n\nNo incluye costos ni notas internas.\n",
            'labels' => ['gestor-ia', 'sap'],
            'assignee_id' => $this->jhon->id,
        ], $overrides);
    }

    private function fakeIssue(int $number = 57): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/GrupoRyR/portafolio/issues' => Http::response([
                'number' => $number, 'html_url' => "https://github.com/GrupoRyR/portafolio/issues/{$number}", 'created_at' => '2026-10-07T14:38:00Z',
            ], 201),
        ]);
    }

    public function test_task_page_and_drawer_show_the_pull_request_ci_and_history(): void
    {
        $this->deliver('pull_request', $this->fixture('pull_request'));
        $this->deliver('workflow_run', $this->fixture('workflow_run'));

        $expected = fn (Assert $page, string $prefix) => $page
            ->where("{$prefix}.repo", ['full_name' => 'GrupoRyR/portafolio', 'url' => 'https://github.com/GrupoRyR/portafolio'])
            ->where("{$prefix}.request_key", 'GIA-139')
            ->where("{$prefix}.task_url", route('ai.tasks.show', [$this->p, $this->t3]))
            ->where("{$prefix}.criteria", [['id' => $this->gia139->criteria()->first()->id, 'text' => 'Filtra por asesor']])
            ->where("{$prefix}.issue", null)
            ->where("{$prefix}.pull_request.number", 41)
            ->where("{$prefix}.pull_request.ci", 'passing')
            ->where("{$prefix}.pull_request.linked.task_key", 'T3')
            ->where("{$prefix}.ci", [
                'status' => 'passing', 'branch' => 'gia-139-t3-endpoint-zonas', 'commit_sha' => 'a1b2c3d',
                'run_at' => '2026-10-07T08:20:00-05:00', 'summary' => 'Pruebas', 'url' => 'https://github.com/GrupoRyR/portafolio/actions/runs/9001',
            ])
            ->has("{$prefix}.events", 3)
            // Newest first: the move (when the PR was processed), the CI run, the PR opening.
            ->where("{$prefix}.events.0", fn ($event) => $event['kind'] === 'moved_review' && $event['title'] === 'Pasó a En revisión' && $event['note'] === 'Leonardo Herrera')
            ->where("{$prefix}.events.1", fn ($event) => $event['kind'] === 'ci_passed' && $event['title'] === 'CI pasó en el PR #41'
                && $event['note'] === 'Pruebas' && $event['at'] === '2026-10-07T08:20:00-05:00')
            ->where("{$prefix}.events.2", fn ($event) => $event['kind'] === 'pr_opened' && $event['title'] === 'Se abrió el PR #41'
                && $event['note'] === 'Leonardo Herrera' && $event['at'] === '2026-10-07T08:10:00-05:00')
            // The task already has a PR but no issue: the form is offered.
            ->where("{$prefix}.issue_form.open", false)
            ->where("{$prefix}.issue_form.assignee_id", $this->superAdmin->id)
            ->where("{$prefix}.issue_form.label_options", ['gestor-ia'])
            ->where("{$prefix}.issue_form.assignees", fn ($people) => collect($people)->contains(fn ($person) => $person['id'] === $this->jhon->id && $person['github_login'] === '@jhonc'));

        $this->actingAs($this->jhon)
            ->get(route('ai.tasks.show', [$this->p, $this->t3]))
            ->assertInertia(fn (Assert $page) => $expected($page->component('Ai/Tasks/Show', false), 'github'));

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', [$this->p, 'tarea' => 'T3']))
            ->assertInertia(fn (Assert $page) => $expected($page, 'selected_task_github')->where('issue_created', null));

        // Partial reload of the drawer.
        $this->actingAs($this->jhon)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Ai/Projects/Board',
                'X-Inertia-Partial-Data' => 'selected_task,selected_task_github',
            ])
            ->get(route('ai.projects.board', [$this->p, 'tarea' => 'T4']))
            ->assertOk()
            ->assertJsonPath('props.selected_task.key', 'T4')
            ->assertJsonPath('props.selected_task_github.pull_request', null)
            ->assertJsonPath('props.selected_task_github.issue_form.assignee_id', $this->jhon->id)
            ->assertJsonMissingPath('props.tasks');
        $this->flushHeaders();

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', $this->p))
            ->assertInertia(fn (Assert $page) => $page->where('selected_task_github', null));
    }

    public function test_github_block_depends_on_permissions(): void
    {
        $it = $this->itWithoutCosts();

        // IT without `ai.github.manage`: GitHub data, no "Crear issue".
        $this->actingAs($it)
            ->get(route('ai.tasks.show', [$this->p, $this->t4]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('github.repo.full_name', 'GrupoRyR/portafolio')
                ->where('github.issue_form', null)
            );

        // A team member without `ai.manage` (requester side) gets no GitHub block.
        $this->p->members()->create(['user_id' => $this->paola->id, 'role' => 'Usuaria clave']);
        $this->actingAs($this->paola)
            ->get(route('ai.tasks.show', [$this->p, $this->t4]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('github', null));

        $this->actingAs($this->paola)
            ->get(route('ai.projects.board', [$this->p, 'tarea' => 'T4']))
            ->assertInertia(fn (Assert $page) => $page->where('selected_task_github', null));

        // Without a repository: the block explains it and offers no form.
        $other = $this->project();
        $task = $this->task($other);
        $this->actingAs($this->jhon)
            ->get(route('ai.tasks.show', [$other, $task]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('github.repo', null)
                ->where('github.request_key', '')
                ->where('github.issue_form', null)
                ->where('github.events', [])
            );

        $this->actingAs($it)->post(route('ai.tasks.issue.store', [$this->p, $this->t4]), $this->issuePayload())->assertForbidden();
        $this->actingAs($this->paola)->post(route('ai.tasks.issue.store', [$this->p, $this->t4]), $this->issuePayload())->assertForbidden();
    }

    public function test_creating_an_issue_from_the_task_page(): void
    {
        $this->fakeIssue();

        $this->actingAs($this->jhon)
            ->from(route('ai.tasks.show', [$this->p, $this->t4]))
            ->post(route('ai.tasks.issue.store', [$this->p, $this->t4]), $this->issuePayload(['labels' => ['sap', 'backend']]))
            ->assertRedirect(route('ai.tasks.show', [$this->p, $this->t4]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Se creó el issue #57 en GitHub.')
            ->assertSessionMissing(GitHubController::ISSUE_CREATED);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://api.github.com/repos/GrupoRyR/portafolio/issues') {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer '.self::INSTALLATION_TOKEN)
                && $request['title'] === 'GIA-139 · T4 Consulta de cartera por asesor'
                && $request['labels'] === ['gestor-ia', 'sap', 'backend']
                && $request['assignees'] === ['jhonc']
                && str_ends_with($request['body'], "No incluye costos ni notas internas.\n");
        });

        $this->t4->refresh();
        $this->assertSame(57, $this->t4->github_issue_number);
        $this->assertSame('https://github.com/GrupoRyR/portafolio/issues/57', $this->t4->github_issue_url);
        $this->assertSame('open', $this->t4->github_issue_state);
        $this->assertSame('creó el issue #57 de T4 Consulta de cartera por asesor', $this->p->activities()->latest('id')->first()->text);

        $this->actingAs($this->jhon)
            ->get(route('ai.tasks.show', [$this->p, $this->t4]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('task.github', ['issue' => ['number' => 57, 'state' => 'open'], 'pr' => null, 'ci' => null])
                ->where('github.issue', [
                    'number' => 57,
                    'title' => 'GIA-139 · T4 Consulta de cartera por asesor',
                    'state' => 'open',
                    'labels' => ['gestor-ia', 'sap', 'backend'],
                    'assignee' => ['name' => 'Jhon Castro', 'login' => '@jhonc'],
                    'created_from_gestor' => true,
                    'created_by' => ['name' => 'Jhon Castro', 'login' => '@jhonc'],
                    'created_at' => '2026-10-07T09:38:00-05:00',
                    'closed_at' => null,
                    'closed_by_pr' => null,
                    'linked' => ['request_key' => 'GIA-139', 'project_key' => $this->p->key, 'task_key' => 'T4', 'task_title' => 'Consulta de cartera por asesor'],
                    'url' => 'https://github.com/GrupoRyR/portafolio/issues/57',
                ])
                ->where('github.issue_form', null)
                ->where('github.events', [[
                    'id' => $this->t4->id * 10 + 3,
                    'kind' => 'issue_created',
                    'title' => 'Se creó el issue #57 en GitHub',
                    'at' => '2026-10-07T09:38:00-05:00',
                    'note' => 'Jhon Castro',
                ]])
            );

        // A second issue for the same task is refused.
        $this->actingAs($this->jhon)
            ->post(route('ai.tasks.issue.store', [$this->p, $this->t4]), $this->issuePayload())
            ->assertSessionHasErrors(['title' => 'Esta tarea ya tiene el issue #57.']);
        Http::assertSentCount(2);
    }

    public function test_creating_an_issue_from_the_board_flashes_issue_created_once(): void
    {
        $this->fakeIssue();
        $board = route('ai.projects.board', [$this->p, 'tarea' => 'T4']);

        $this->actingAs($this->jhon)
            ->from($board)
            ->post(route('ai.tasks.issue.store', [$this->p, $this->t4]), $this->issuePayload(['labels' => []]))
            ->assertRedirect($board)
            ->assertSessionMissing('success');

        $this->actingAs($this->jhon)
            ->get($board)
            ->assertInertia(fn (Assert $page) => $page
                ->where('issue_created.task_key', 'T4')
                ->where('issue_created.repo_full_name', 'GrupoRyR/portafolio')
                ->where('issue_created.issue.number', 57)
                ->where('issue_created.issue.labels', ['gestor-ia'])
                ->where('selected_task_github.issue.number', 57)
                ->where('selected_task.github.issue', ['number' => 57, 'state' => 'open'])
            );

        $this->actingAs($this->jhon)
            ->get($board)
            ->assertInertia(fn (Assert $page) => $page->where('issue_created', null));
    }

    public function test_issue_text_and_assignee_are_validated_on_the_server(): void
    {
        $this->fakeIssue();
        $store = route('ai.tasks.issue.store', [$this->p, $this->t4]);
        AiRequestComment::create([
            'ai_request_id' => $this->gia139->id, 'user_id' => $this->jhon->id,
            'body' => 'El cliente pidió descuento especial por volumen', 'visibility' => CommentVisibility::Internal,
        ]);

        $this->actingAs($this->jhon)->post($store, ['title' => '', 'body' => ''])
            ->assertSessionHasErrors(['title' => 'Escribe el título del issue.', 'body' => 'Escribe la descripción del issue.']);

        $this->actingAs($this->jhon)->post($store, $this->issuePayload(['body' => 'Costo estimado $ 1.200.000 en horas']))
            ->assertSessionHasErrors(['body' => 'El issue no puede incluir costos, tarifas ni valores en dinero.']);

        $this->actingAs($this->jhon)->post($store, $this->issuePayload(['body' => "Contexto: el cliente pidió descuento especial por volumen.\n"]))
            ->assertSessionHasErrors(['body' => 'El issue no puede incluir notas internas de la solicitud.']);

        $this->actingAs($this->jhon)->post($store, $this->issuePayload(['body' => 'password = Sup3rS3creta']))
            ->assertSessionHasErrors('body');

        $this->actingAs($this->jhon)->post($store, $this->issuePayload(['assignee_id' => $this->paola->id]))
            ->assertSessionHasErrors(['assignee_id' => 'Esa persona no tiene usuario de GitHub registrado.']);

        Http::assertNothingSent();

        // The footer is added when the page left it out.
        $this->actingAs($this->jhon)->post($store, $this->issuePayload(['body' => 'Solo la descripción', 'assignee_id' => null]))
            ->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/issues')
            && $request['body'] === "Solo la descripción\n\n---\n\nNo incluye costos ni notas internas.\n"
            && $request['assignees'] === []);
    }

    public function test_github_failures_and_missing_repository_are_validation_errors(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/GrupoRyR/portafolio/issues' => Http::response(['message' => 'Server Error'], 500),
        ]);

        $response = $this->actingAs($this->jhon)
            ->post(route('ai.tasks.issue.store', [$this->p, $this->t4]), $this->issuePayload())
            ->assertSessionHasErrors(['title' => 'GitHub no respondió. Intenta de nuevo en unos minutos.']);

        $this->assertStringNotContainsString(self::INSTALLATION_TOKEN, (string) json_encode(session()->all()));
        $this->assertStringNotContainsString(self::INSTALLATION_TOKEN, (string) $response->getContent());
        $this->assertNull($this->t4->fresh()->github_issue_number);

        $other = $this->project();
        $task = $this->task($other);
        $this->actingAs($this->jhon)
            ->post(route('ai.tasks.issue.store', [$other, $task]), $this->issuePayload())
            ->assertSessionHasErrors(['title' => 'El proyecto no tiene un repositorio vinculado. Vincúlalo en el resumen del proyecto.']);

        // Tasks are bound inside their project.
        $this->actingAs($this->jhon)
            ->post(route('ai.tasks.issue.store', [$other, $this->t4->key.'0']), $this->issuePayload())
            ->assertNotFound();
    }
}
