<?php

namespace Tests\Feature\Ai\Knowledge;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AiDocument;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

class DocumentsTest extends KnowledgeTestCase
{
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

    public function test_repo_page_is_not_connected_until_stage_d(): void
    {
        $this->product(['slug' => 'cartera', 'name' => 'Cartera']);
        $this->product(['slug' => 'pedidos', 'name' => 'Pedidos']);

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
    }

    private function itWithoutDocs(): User
    {
        $role = Role::create(['name' => 'TI sin documentación', 'guard_name' => 'web']);
        $role->givePermissionTo(['ai.manage', 'ai.requests.create', 'companies.view_all']);

        return $this->user($this->segurex, 'TI sin documentación', 'Leonardo TI');
    }
}
