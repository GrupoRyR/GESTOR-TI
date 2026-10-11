<?php

namespace Tests\Feature\Ai\Knowledge;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AiDocument;
use App\Models\AiProductVersion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\GitHub\BuildsGitHubWork;

class DocumentsTest extends KnowledgeTestCase
{
    use BuildsGitHubWork;

    public function test_index_returns_exactly_the_contract_props_and_lists_visible_docs(): void
    {
        $product = $this->product(['slug' => 'cartera', 'name' => 'Cartera']);
        $manual = $this->doc($product, 'user_manual', 'Manual de usuario');
        $project = $this->project(['name' => 'Cartera · filtros']);
        $sheet = $this->doc($project, 'project_sheet', 'Ficha del proyecto');
        $hidden = $this->doc($this->project(['company_id' => $this->third->id, 'area_id' => null]), 'project_sheet', 'Ficha de la tercera');

        $this->assertSame($this->withShared(['docs', 'now']), $this->propKeys(route('ai.docs.index')));

        $this->actingAs($this->jhon)
            ->get(route('ai.docs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Docs/Index', false)
                ->has('docs', 3)
                ->where('docs.0.id', $manual->id)
                ->where('docs.0', [
                    'id' => $manual->id,
                    'title' => 'Manual de usuario',
                    'template' => 'user_manual',
                    'owner' => ['kind' => 'product', 'key' => 'cartera', 'name' => 'Cartera'],
                    'version' => 1,
                    'not_applicable' => false,
                    'updated_at' => $manual->updated_at->toIso8601String(),
                    'updated_by' => ['id' => $this->jhon->id, 'name' => 'Jhon Castro', 'job_title' => null, 'avatar_url' => $this->jhon->avatar_url],
                ])
            );

        // A company context hides the docs of the other companies.
        $this->actingAs($this->jhon)
            ->withSession(['current_company_id' => $this->segurex->id])
            ->get(route('ai.docs.index'))
            ->assertInertia(fn (Assert $page) => $page->has('docs', 2)->where('docs.1.id', $sheet->id));

        $this->actingAs($this->paola)->get(route('ai.docs.index'))->assertForbidden();
        $this->actingAs($this->paola)->get(route('ai.docs.edit', $manual))->assertForbidden();
        $this->actingAs($this->jhon)->withSession(['current_company_id' => $this->segurex->id])->get(route('ai.docs.edit', $hidden))->assertForbidden();
    }

    public function test_project_docs_tab_lists_only_the_project_docs(): void
    {
        $project = $this->project();
        $doc = $this->doc($project, 'decisions', 'Registro de decisiones');
        $this->doc($this->project(), 'decisions', 'Otro');
        $this->doc($this->product(), 'user_manual');

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.docs', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Docs/Index', false)
                ->has('docs', 1)
                ->where('docs.0.id', $doc->id)
                ->where('docs.0.owner', ['kind' => 'project', 'key' => $project->key, 'name' => $project->name])
            );

        $this->actingAs($this->paola)->get(route('ai.projects.docs', $project))->assertForbidden();
    }

    public function test_create_page_props_owners_and_defaults(): void
    {
        $product = $this->product(['slug' => 'cartera', 'name' => 'Cartera']);
        $project = $this->project(['name' => 'Cartera · filtros']);

        $this->assertSame($this->withShared(['owners', 'defaults']), $this->propKeys(route('ai.docs.create')));

        $this->actingAs($this->jhon)
            ->get(route('ai.docs.create', ['owner_kind' => 'project', 'owner_key' => $project->key]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Docs/Create', false)
                ->where('owners', [
                    ['kind' => 'product', 'key' => 'cartera', 'name' => 'Cartera'],
                    ['kind' => 'project', 'key' => $project->key, 'name' => 'Cartera · filtros'],
                ])
                ->where('defaults', ['owner_kind' => 'project', 'owner_key' => $project->key])
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.docs.create', ['owner_kind' => 'product', 'owner_key' => 'no-existe']))
            ->assertInertia(fn (Assert $page) => $page->where('defaults', null));

        $this->assertNotNull($product);
    }

    public function test_store_creates_the_doc_from_its_template_with_version_one(): void
    {
        $product = $this->product(['slug' => 'cartera', 'name' => 'Cartera']);

        $this->actingAs($this->jhon)
            ->post(route('ai.docs.store'), ['template' => 'architecture', 'owner_kind' => 'product', 'owner_key' => 'cartera', 'title' => 'Arquitectura y datos'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('ai.docs.edit', AiDocument::query()->sole()));

        $doc = AiDocument::query()->sole();
        $this->assertSame('Arquitectura y datos', $doc->title);
        $this->assertSame('architecture', $doc->template);
        $this->assertSame('it', $doc->visibility);
        $this->assertSame($product->id, $doc->ai_product_id);
        $this->assertSame(1, $doc->version);
        $this->assertStringContainsString('Nunca escribas contraseñas', $doc->body);
        $this->assertSame($this->jhon->id, $doc->author_id);
        $this->assertSame(1, $doc->revisions()->count());
        $this->assertSame('Primera versión.', $doc->revisions()->first()->summary);

        $this->actingAs($this->jhon)
            ->post(route('ai.docs.store'), ['template' => 'nope', 'owner_kind' => 'product', 'owner_key' => 'otro', 'title' => ''])
            ->assertSessionHasErrors(['template', 'owner_key', 'title']);

        $this->actingAs($this->itWithoutDocs())
            ->post(route('ai.docs.store'), ['template' => 'blank', 'owner_kind' => 'product', 'owner_key' => 'cartera', 'title' => 'X'])
            ->assertForbidden();
    }

    public function test_edit_props_and_the_autosave_partial_reload(): void
    {
        $doc = $this->doc($this->product(), 'user_manual', 'Manual', "# Manual\n");

        $this->assertSame($this->withShared(['doc', 'tree', 'saved_at', 'version', 'company_id', 'now']), $this->propKeys(route('ai.docs.edit', $doc)));

        $this->actingAs($this->jhon)
            ->get(route('ai.docs.edit', $doc))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Docs/Edit', false)
                ->where('doc.markdown', "# Manual\n")
                ->where('doc.visibility', 'company')
                ->where('doc.repository_path', null)
                ->where('doc.updated_by.name', 'Jhon Castro')
                ->where('version', 1)
                ->where('company_id', $this->segurex->id)
                ->has('tree', 1)
            );

        $this->travel(5)->minutes();

        $this->actingAs($this->jhon)
            ->from(route('ai.docs.edit', $doc))
            ->put(route('ai.docs.update', $doc), ['markdown' => "# Manual\n\nNuevo texto.\n    - sangría\n"])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('ai.docs.edit', $doc));

        // Exact whitespace (TrimStrings skips `markdown`).
        $doc->refresh();
        $this->assertSame("# Manual\n\nNuevo texto.\n    - sangría\n", $doc->body);
        $this->assertSame(1, $doc->version);
        $this->assertSame(1, $doc->revisions()->count());

        $this->actingAs($this->jhon)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Ai/Docs/Edit',
                'X-Inertia-Partial-Data' => 'saved_at,version',
            ])
            ->get(route('ai.docs.edit', $doc))
            ->assertOk()
            ->assertJsonPath('props.saved_at', $doc->updated_at->toIso8601String())
            ->assertJsonPath('props.version', 1)
            ->assertJsonMissingPath('props.doc')
            ->assertJsonMissingPath('props.tree');
    }

    public function test_publish_adds_a_version_and_stale_saves_conflict(): void
    {
        $doc = $this->doc($this->product(), 'user_manual', 'Manual', "Uno\n");

        $this->actingAs($this->jhon)
            ->put(route('ai.docs.update', $doc), ['markdown' => "Uno\nDos"])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->jhon)
            ->post(route('ai.docs.publish', $doc), ['summary' => 'Agrega la línea dos.'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Publicaste la versión 2.');

        $doc->refresh();
        $this->assertSame(2, $doc->version);
        $revision = $doc->revisions()->where('version', 2)->sole();
        $this->assertSame("Uno\nDos", $revision->body);
        $this->assertSame('Agrega la línea dos.', $revision->summary);

        $this->actingAs($this->jhon)
            ->post(route('ai.docs.publish', $doc), ['summary' => ''])
            ->assertSessionHasErrors('summary');

        // Optimistic concurrency: a save based on an older version is refused.
        $this->actingAs($this->jhon)
            ->put(route('ai.docs.update', $doc), ['markdown' => 'Pisado', 'version' => 1])
            ->assertSessionHasErrors('markdown');
        $this->actingAs($this->jhon)
            ->post(route('ai.docs.publish', $doc), ['summary' => 'Otra', 'version' => 1])
            ->assertSessionHasErrors('markdown');
        $this->assertSame("Uno\nDos", $doc->fresh()->body);
        $this->assertSame(2, $doc->fresh()->version);

        $this->actingAs($this->jhon)
            ->put(route('ai.docs.update', $doc), ['markdown' => "Uno\nDos\nTres", 'version' => 2])
            ->assertSessionHasNoErrors();
        $this->assertSame("Uno\nDos\nTres", $doc->fresh()->body);
    }

    public function test_bodies_with_secrets_are_rejected(): void
    {
        $doc = $this->doc($this->product(), 'architecture', 'Arquitectura');

        foreach ([
            "DB_PASSWORD=supersecreta\n",
            "password: hunter22\n",
            "api_key = 'abcd1234efgh5678'\n",
            "-----BEGIN RSA PRIVATE KEY-----\nMIIE\n",
            "Llave AKIAIOSFODNN7EXAMPLE en AWS\n",
        ] as $body) {
            $this->actingAs($this->jhon)
                ->put(route('ai.docs.update', $doc), ['markdown' => $body])
                ->assertSessionHasErrors(['markdown' => 'El documento parece tener una contraseña o una llave. Las credenciales van en la bóveda, nunca en la documentación.']);
        }

        // Naming a variable is fine; its value is not.
        $this->actingAs($this->jhon)
            ->put(route('ai.docs.update', $doc), ['markdown' => "La llave va en el `.env` como `DEEPSEEK_API_KEY` y una copia en la bóveda.\n"])
            ->assertSessionHasNoErrors();
    }

    public function test_edit_is_read_only_data_for_it_without_docs_permission(): void
    {
        $doc = $this->doc($this->product(), 'user_manual');
        $viewer = $this->itWithoutDocs();

        $this->actingAs($viewer)->get(route('ai.docs.edit', $doc))->assertOk();
        $this->actingAs($viewer)->put(route('ai.docs.update', $doc), ['markdown' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->post(route('ai.docs.publish', $doc), ['summary' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->post(route('ai.docs.restore', [$doc, 1]))->assertForbidden();
    }

    public function test_history_lists_versions_and_compares_them(): void
    {
        $doc = $this->doc($this->product(), 'user_manual', 'Manual', "a\nb\n");
        $doc->revisions()->where('version', 1)->update(['body' => "a\nb\n"]);
        $this->actingAs($this->jhon)->put(route('ai.docs.update', $doc), ['markdown' => "a\nc"]);
        $this->actingAs($this->jhon)->post(route('ai.docs.publish', $doc), ['summary' => 'Cambia b por c.']);

        $this->assertSame($this->withShared(['doc', 'versions', 'compare', 'now']), $this->propKeys(route('ai.docs.history', $doc)));

        $this->actingAs($this->jhon)
            ->get(route('ai.docs.history', $doc))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Docs/History', false)
                ->where('doc.id', $doc->id)
                ->has('versions', 2)
                ->where('versions.0.version', 2)
                ->where('versions.0.summary', 'Cambia b por c.')
                ->where('versions.0.current', true)
                ->where('versions.0.author.name', 'Jhon Castro')
                ->where('versions.1.current', false)
                ->where('compare', [
                    'version' => 1,
                    'against' => 2,
                    'lines' => [
                        ['kind' => 'same', 'old_line' => 1, 'new_line' => 1, 'text' => 'a'],
                        ['kind' => 'remove', 'old_line' => 2, 'new_line' => null, 'text' => 'b'],
                        ['kind' => 'add', 'old_line' => null, 'new_line' => 2, 'text' => 'c'],
                    ],
                    'markdown' => "a\nb\n",
                ])
            );

        // Choosing the current version compares it with the previous one (partial reload).
        $this->actingAs($this->jhon)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Ai/Docs/History',
                'X-Inertia-Partial-Data' => 'compare',
            ])
            ->get(route('ai.docs.history', [$doc, 'version' => 2]))
            ->assertOk()
            ->assertJsonPath('props.compare.version', 2)
            ->assertJsonPath('props.compare.against', 1)
            ->assertJsonPath('props.compare.markdown', "a\nc")
            ->assertJsonMissingPath('props.versions');
        $this->flushHeaders();

        $single = $this->doc($this->product(), 'blank', 'Notas');
        $this->actingAs($this->jhon)
            ->get(route('ai.docs.history', $single))
            ->assertInertia(fn (Assert $page) => $page->has('versions', 1)->where('compare', null));
    }

    public function test_restore_creates_a_new_version_and_deletes_nothing(): void
    {
        $doc = $this->doc($this->product(), 'user_manual', 'Manual');
        $first = $doc->body;
        $this->actingAs($this->jhon)->put(route('ai.docs.update', $doc), ['markdown' => 'Segunda']);
        $this->actingAs($this->jhon)->post(route('ai.docs.publish', $doc), ['summary' => 'Segunda']);

        $this->actingAs($this->jhon)
            ->post(route('ai.docs.restore', [$doc, 1]))
            ->assertRedirect(route('ai.docs.history', $doc))
            ->assertSessionHas('success', 'Restauraste la versión 1 como versión 3.');

        $doc->refresh();
        $this->assertSame(3, $doc->version);
        $this->assertSame($first, $doc->body);
        $this->assertSame(3, $doc->revisions()->count());
        $this->assertSame('Restaurada la versión 1.', $doc->revisions()->where('version', 3)->sole()->summary);

        $this->actingAs($this->jhon)->post(route('ai.docs.restore', [$doc, 9]))->assertNotFound();
    }

    public function test_repo_page_is_not_connected_without_a_readable_repository(): void
    {
        $this->product(['slug' => 'cartera', 'name' => 'Cartera']);
        $pedidos = $this->product(['slug' => 'pedidos', 'name' => 'Pedidos']);

        $this->assertSame($this->withShared(['products', 'product_slug', 'repo', 'now']), $this->propKeys(route('ai.docs.repo')));

        $this->actingAs($this->jhon)
            ->get(route('ai.docs.repo', ['product' => 'pedidos']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Docs/Repo', false)
                ->where('products', [['slug' => 'cartera', 'name' => 'Cartera'], ['slug' => 'pedidos', 'name' => 'Pedidos']])
                ->where('product_slug', 'pedidos')
                ->where('repo', ['connected' => false])
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.docs.repo', ['product' => 'nada']))
            ->assertInertia(fn (Assert $page) => $page->where('product_slug', 'cartera'));

        // Linked, but GitHub is not configured, or the link does not read docs.
        Http::fake();
        $repo = $this->githubRepo('pedidos');
        $repo->linkTo($pedidos, 'main', $this->jhon);
        $this->actingAs($this->jhon)
            ->get(route('ai.docs.repo', ['product' => 'pedidos']))
            ->assertInertia(fn (Assert $page) => $page->where('repo', ['connected' => false]));

        $this->connectGitHub();
        $repo->linkTo($pedidos, 'main', $this->jhon, readDocs: false);
        $this->actingAs($this->jhon)
            ->get(route('ai.docs.repo', ['product' => 'pedidos']))
            ->assertInertia(fn (Assert $page) => $page->where('repo', ['connected' => false]));
        Http::assertNothingSent();
    }

    public function test_repo_page_reads_the_markdown_of_the_linked_repository(): void
    {
        $this->freezeSecond();
        $this->connectGitHub();
        $product = $this->product(['slug' => 'cartera', 'name' => 'Visualizador de cartera']);
        $repo = $this->githubRepo('visualizador-cartera', ['branches' => ['main', 'develop']]);
        $repo->linkTo($product, 'develop', $this->jhon);
        AiProductVersion::factory()->create([
            'ai_product_id' => $product->id, 'version' => '3.1.0', 'tag' => 'v3.1.0', 'github_repository_id' => $repo->id,
            'published_at' => '2026-09-22 16:00:00', 'released_at' => '2026-09-22',
        ]);

        $commit = fn (string $date) => Http::response([['sha' => 'abc', 'commit' => ['committer' => ['date' => $date]]]]);
        $this->fakeGitHub([
            'api.github.com/repos/GrupoRyR/visualizador-cartera/git/trees/develop*' => Http::response(['tree' => [
                ['path' => 'README.md', 'type' => 'blob'],
                ['path' => 'CLAUDE.md', 'type' => 'blob'],
                ['path' => 'CHANGELOG.md', 'type' => 'blob'],
                ['path' => 'package.json', 'type' => 'blob'],
                ['path' => 'src', 'type' => 'tree'],
                ['path' => 'src/notas.md', 'type' => 'blob'],
                ['path' => 'docs', 'type' => 'tree'],
                ['path' => 'docs/arquitectura.md', 'type' => 'blob'],
                ['path' => 'docs/diagrama.png', 'type' => 'blob'],
                ['path' => 'docs/decisiones', 'type' => 'tree'],
                ['path' => 'docs/decisiones/0001-next-vercel.md', 'type' => 'blob'],
            ]]),
            'api.github.com/repos/GrupoRyR/visualizador-cartera/commits?*path=README.md*' => $commit('2026-09-20T15:00:00Z'),
            'api.github.com/repos/GrupoRyR/visualizador-cartera/commits?*path=docs%2Farquitectura.md*' => $commit('2026-09-25T15:00:00Z'),
            'api.github.com/repos/GrupoRyR/visualizador-cartera/commits*' => $commit('2026-08-18T15:00:00Z'),
            'api.github.com/repos/GrupoRyR/visualizador-cartera/contents/README.md*' => Http::response([
                'type' => 'file', 'content' => base64_encode("# Visualizador\n\nCartera por asesor.\n"),
            ]),
            'api.github.com/repos/GrupoRyR/visualizador-cartera/contents/docs/arquitectura.md*' => Http::response([
                'type' => 'file', 'content' => base64_encode("# Arquitectura\n"),
            ]),
        ]);

        $this->actingAs($this->jhon)
            ->get(route('ai.docs.repo', ['product' => 'cartera']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('repo.connected', true)
                ->where('repo.name', 'GrupoRyR/visualizador-cartera')
                ->where('repo.branch', 'develop')
                ->where('repo.fetched_at', now()->toIso8601String())
                ->where('repo.files', [
                    ['path' => 'README.md', 'kind' => 'file', 'depth' => 0, 'updated_at' => '2026-09-20'],
                    ['path' => 'CLAUDE.md', 'kind' => 'file', 'depth' => 0, 'updated_at' => '2026-08-18'],
                    ['path' => 'CHANGELOG.md', 'kind' => 'file', 'depth' => 0, 'updated_at' => '2026-08-18'],
                    ['path' => 'docs/', 'kind' => 'dir', 'depth' => 0, 'updated_at' => null],
                    ['path' => 'docs/arquitectura.md', 'kind' => 'file', 'depth' => 1, 'updated_at' => '2026-09-25'],
                    ['path' => 'docs/decisiones/', 'kind' => 'dir', 'depth' => 1, 'updated_at' => null],
                    ['path' => 'docs/decisiones/0001-next-vercel.md', 'kind' => 'file', 'depth' => 2, 'updated_at' => '2026-08-18'],
                ])
                ->where('repo.selected', [
                    'path' => 'README.md',
                    'markdown' => "# Visualizador\n\nCartera por asesor.\n",
                    'updated_at' => '2026-09-20',
                    'release' => 'versión 3.1.0',
                    'github_url' => 'https://github.com/GrupoRyR/visualizador-cartera/blob/develop/README.md',
                ])
            );

        // Choosing a file is a partial reload of `repo`; the listing comes from the cache.
        $sent = count(Http::recorded());
        $this->actingAs($this->jhon)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Ai/Docs/Repo',
                'X-Inertia-Partial-Data' => 'repo',
            ])
            ->get(route('ai.docs.repo', ['product' => 'cartera', 'path' => 'docs/arquitectura.md']))
            ->assertOk()
            ->assertJsonPath('props.repo.selected.path', 'docs/arquitectura.md')
            ->assertJsonPath('props.repo.selected.markdown', "# Arquitectura\n")
            ->assertJsonPath('props.repo.selected.release', null)
            ->assertJsonMissingPath('props.products');
        $this->flushHeaders();
        $this->assertSame($sent + 1, count(Http::recorded()));
    }

    public function test_repo_page_falls_back_when_github_fails(): void
    {
        $this->connectGitHub();
        $product = $this->product(['slug' => 'cartera', 'name' => 'Cartera']);
        $this->githubRepo('cartera')->linkTo($product, 'main', $this->jhon);
        $this->fakeGitHub(['api.github.com/repos/*' => Http::response(['message' => 'Server Error'], 500)]);

        $this->actingAs($this->jhon)
            ->get(route('ai.docs.repo', ['product' => 'cartera']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('repo', ['connected' => false]));
    }

    private function itWithoutDocs(): User
    {
        $role = Role::create(['name' => 'TI sin documentación', 'guard_name' => 'web']);
        $role->givePermissionTo(['ai.manage', 'ai.requests.create', 'companies.view_all']);

        return $this->user($this->segurex, 'TI sin documentación', 'Leonardo TI');
    }
}
