<?php

namespace Tests\Feature\GitHub;

use App\Models\AiProject;
use App\Models\GitHubRepository;
use App\Support\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ai\Projects\ProjectsTestCase;

/**
 * "Crear repositorio con este CLAUDE.md" (`ai.projects.repository.create`):
 * a private organization repository with README.md, CLAUDE.md and docs/,
 * stored and linked to the project.
 */
class RepositoryCreateTest extends ProjectsTestCase
{
    use BuildsGitHubWork;

    private const CLAUDE_MD = "# CLAUDE.md\n\nProyecto P-6 · descuentos por volumen.\n";

    private AiProject $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectGitHub();
        $this->p = $this->project(['name' => 'Toma Pedidos · descuentos por volumen']);
    }

    private function fakeCreation(int $status = 201): void
    {
        $this->fakeGitHub([
            'api.github.com/orgs/GrupoRyR/repos' => Http::response($status === 201 ? [
                'id' => 9876, 'name' => 'descuentos', 'full_name' => 'GrupoRyR/descuentos', 'private' => true,
                'html_url' => 'https://github.com/GrupoRyR/descuentos', 'default_branch' => 'main', 'pushed_at' => null,
            ] : ['message' => 'Repository creation failed.'], $status),
            'api.github.com/repos/GrupoRyR/descuentos/contents/*' => Http::response(['content' => ['path' => 'x']], 201),
        ]);
    }

    public function test_it_creates_a_private_repository_and_links_it(): void
    {
        $this->fakeCreation();

        $this->actingAs($this->jhon)
            ->post(route('ai.projects.repository.create', $this->p), ['name' => 'descuentos', 'claude_md' => self::CLAUDE_MD])
            ->assertRedirect(route('ai.projects.show', $this->p))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Se creó el repositorio privado GrupoRyR/descuentos y quedó vinculado al proyecto.');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/orgs/GrupoRyR/repos'
            && $request['name'] === 'descuentos' && $request['private'] === true);

        $files = [];
        Http::recorded(function (Request $request) use (&$files) {
            if ($request->method() === 'PUT') {
                $files[substr($request->url(), strlen('https://api.github.com/repos/GrupoRyR/descuentos/contents/'))] = base64_decode($request['content']);
            }

            return true;
        });
        $this->assertSame(['README.md', 'CLAUDE.md', 'docs/README.md'], array_keys($files));
        $this->assertSame(self::CLAUDE_MD, $files['CLAUDE.md']);
        $this->assertStringContainsString('# Toma Pedidos · descuentos por volumen', $files['README.md']);

        $repo = GitHubRepository::query()->where('full_name', 'GrupoRyR/descuentos')->sole();
        $this->assertSame(9876, $repo->github_id);
        $this->assertTrue($repo->linkable->is($this->p));
        $this->assertSame('main', $repo->branch);
        $this->assertSame($this->jhon->id, $repo->linked_by_id);
        $this->assertSame('https://github.com/GrupoRyR/descuentos', $this->p->fresh()->repository_url);
        $this->assertSame('creó el repositorio GrupoRyR/descuentos', $this->p->activities()->latest('id')->first()->text);

        // Only once: the project now has a repository.
        $this->actingAs($this->jhon)
            ->post(route('ai.projects.repository.create', $this->p), ['name' => 'otro', 'claude_md' => self::CLAUDE_MD])
            ->assertSessionHasErrors(['name' => 'Este proyecto ya tiene un repositorio vinculado.']);
    }

    public function test_name_and_claude_md_are_validated(): void
    {
        $this->fakeCreation();
        $create = route('ai.projects.repository.create', $this->p);
        $this->githubRepo('existente');

        $this->actingAs($this->jhon)->post($create, ['name' => '', 'claude_md' => ''])
            ->assertSessionHasErrors(['name' => 'Escribe el nombre del repositorio.', 'claude_md' => 'El CLAUDE.md no puede quedar vacío.']);
        $this->actingAs($this->jhon)->post($create, ['name' => 'descuentos por volumen', 'claude_md' => self::CLAUDE_MD])
            ->assertSessionHasErrors(['name' => 'Usa solo letras sin tildes, números, puntos, guiones y guiones bajos.']);
        $this->actingAs($this->jhon)->post($create, ['name' => 'descuentos.git', 'claude_md' => self::CLAUDE_MD])
            ->assertSessionHasErrors(['name' => 'El nombre no puede terminar en .git.']);
        $this->actingAs($this->jhon)->post($create, ['name' => 'existente', 'claude_md' => self::CLAUDE_MD])
            ->assertSessionHasErrors(['name' => 'Ya existe un repositorio con ese nombre en la organización.']);
        $this->actingAs($this->jhon)->post($create, ['name' => 'descuentos', 'claude_md' => "Base\nDB_PASSWORD=Sup3rS3creta\n"])
            ->assertSessionHasErrors('claude_md');

        Http::assertNothingSent();
    }

    public function test_github_failures_never_become_a_500(): void
    {
        $create = route('ai.projects.repository.create', $this->p);

        $this->fakeCreation(500);
        $response = $this->actingAs($this->jhon)->post($create, ['name' => 'descuentos', 'claude_md' => self::CLAUDE_MD])
            ->assertRedirect()
            ->assertSessionHasErrors(['name' => 'GitHub no respondió. Intenta de nuevo en unos minutos.']);
        $this->assertStringNotContainsString(self::INSTALLATION_TOKEN, (string) json_encode(session()->all()));
        $this->assertStringNotContainsString(self::INSTALLATION_TOKEN, (string) $response->getContent());

        // Not connected: refused before calling GitHub.
        app(Settings::class)->forget('github.installation_id');
        Http::fake();
        $this->actingAs($this->jhon)->post($create, ['name' => 'descuentos', 'claude_md' => self::CLAUDE_MD])
            ->assertSessionHasErrors(['name' => 'GitHub no está conectado. Revísalo en Administración › Integraciones › GitHub.']);
        Http::assertNothingSent();

        $this->assertNull($this->p->fresh()->repository_url);
        $this->assertSame(0, GitHubRepository::query()->count());
    }

    public function test_a_name_taken_on_github_is_a_name_error(): void
    {
        $this->fakeCreation(422);

        $this->actingAs($this->jhon)
            ->post(route('ai.projects.repository.create', $this->p), ['name' => 'descuentos', 'claude_md' => self::CLAUDE_MD])
            ->assertRedirect()
            ->assertSessionHasErrors(['name' => 'Ya existe un repositorio con ese nombre en la organización.']);
    }

    public function test_only_github_managers_can_create_it(): void
    {
        Http::fake();

        $this->actingAs($this->itWithoutCosts())
            ->post(route('ai.projects.repository.create', $this->p), ['name' => 'descuentos', 'claude_md' => self::CLAUDE_MD])
            ->assertForbidden();
        $this->actingAs($this->paola)
            ->post(route('ai.projects.repository.create', $this->p), ['name' => 'descuentos', 'claude_md' => self::CLAUDE_MD])
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
