<?php

namespace Tests\Feature\Ai\Knowledge;

use App\Enums\Ai\ProductStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\AiProduct;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * `Ai/Products/Create` and `Ai/Products/Edit` (C-ProductForm,
 * types/pages/ai-knowledge.ts): `ai.manage` only, no delete.
 */
class ProductFormsTest extends KnowledgeTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Portafolio Derivados',
            'slug' => 'portafolio-derivados',
            'company_ids' => [$this->derivados->id],
            'area_id' => $this->derivadosVentas->id,
            'owner_id' => $this->paola->id,
            'description' => 'Catálogo de productos para los asesores.',
            'request_keys' => [],
            'version' => 'v1.0.0',
            'state' => 'pilot',
            'kind' => 'web',
            'stack' => 'Laravel',
            'host' => 'GoDaddy',
            'url' => 'https://portafolio.example.test',
        ], $overrides);
    }

    public function test_create_returns_exactly_the_contract_props(): void
    {
        $this->assertSame(
            $this->withShared(['areas', 'owners', 'hosts', 'requests', 'form']),
            $this->propKeys(route('ai.products.create')),
        );
    }

    public function test_create_starts_blank_with_the_company_of_the_context(): void
    {
        $linkable = $this->request(['title' => 'Exportar a Excel']);
        $this->request(['title' => 'Ya entregada', 'status' => RequestStatus::InProduction]);
        $other = $this->product();
        $this->request(['title' => 'De otro producto', 'product_id' => $other->id]);

        $this->actingAs($this->jhon)
            ->withSession(['current_company_id' => $this->segurex->id])
            ->get(route('ai.products.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Products/Create', false)
                ->where('form', [
                    'name' => '',
                    'slug' => '',
                    'company_ids' => [$this->segurex->id],
                    'area_id' => null,
                    'owner_id' => null,
                    'description' => '',
                    'request_keys' => [],
                    'version' => 'v0.1.0',
                    'state' => 'development',
                    'kind' => 'web',
                    'stack' => '',
                    'host' => '',
                    'url' => '',
                ])
                ->where('hosts', ['GoDaddy', 'Vercel', 'Equipo local', 'Servidor de SAP'])
                ->where('requests', [['key' => $linkable->key, 'title' => 'Exportar a Excel', 'status' => 'received']])
                ->has('areas')
                ->has('owners.0.id')
                ->has('owners.0.name')
            );
    }

    public function test_forms_need_ai_manage(): void
    {
        $product = $this->product();

        $this->actingAs($this->paola)->get(route('ai.products.create'))->assertForbidden();
        $this->actingAs($this->paola)->get(route('ai.products.edit', $product))->assertForbidden();
        $this->actingAs($this->paola)->post(route('ai.products.store'), $this->payload())->assertForbidden();
        $this->actingAs($this->paola)->put(route('ai.products.update', $product), $this->payload())->assertForbidden();

        $this->assertDatabaseMissing('ai_products', ['slug' => 'portafolio-derivados']);
    }

    public function test_store_creates_the_product_with_companies_area_requests_and_version(): void
    {
        $request = $this->request(['title' => 'Exportar a Excel', 'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id]);

        $this->actingAs($this->jhon)
            ->post(route('ai.products.store'), $this->payload([
                'company_ids' => [$this->segurex->id, $this->derivados->id],
                'request_keys' => [$request->key],
            ]))
            ->assertRedirect(route('ai.products.show', 'portafolio-derivados'))
            ->assertSessionHas('success', 'Producto creado.');

        $product = AiProduct::query()->where('slug', 'portafolio-derivados')->firstOrFail();
        $this->assertSame('Portafolio Derivados', $product->name);
        $this->assertSame(ProductStatus::Pilot, $product->status);
        $this->assertSame('web', $product->kind);
        $this->assertSame('v1.0.0', $product->current_version);
        $this->assertSame('GoDaddy', $product->hosting);
        $this->assertSame($this->paola->id, $product->owner_user_id);
        // The area's company is the primary one.
        $this->assertSame($this->derivados->id, $product->primaryCompany()->id);
        $this->assertSame([$this->segurex->id, $this->derivados->id], $product->companies()->orderBy('companies.id')->pluck('companies.id')->all());
        $this->assertSame($this->derivadosVentas->id, $product->primaryArea()->id);
        $this->assertSame($product->id, $request->fresh()->product_id);
        $this->assertSame(['v1.0.0'], $product->versions()->pluck('version')->all());

        $this->assertTrue(Activity::query()->where('subject_type', $product->getMorphClass())->where('subject_id', $product->id)->where('event', 'created')->exists());
    }

    public function test_store_validation_messages_are_spanish_with_the_contract_keys(): void
    {
        $this->product(['slug' => 'portafolio', 'name' => 'Portafolio de productos']);

        $this->actingAs($this->jhon)
            ->from(route('ai.products.create'))
            ->post(route('ai.products.store'), $this->payload([
                'name' => '',
                'slug' => 'portafolio',
                'company_ids' => [],
                'area_id' => null,
                'version' => 'v1.0',
                'state' => 'borrado',
                'kind' => 'tablet',
                'url' => 'no es una url',
                'request_keys' => ['GIA-999'],
            ]))
            ->assertRedirect(route('ai.products.create'))
            ->assertSessionHasErrors([
                'name' => 'Escribe el nombre del producto.',
                'slug' => 'Ese slug ya lo usa Portafolio de productos. Prueba con otro.',
                'company_ids' => 'Elige la empresa.',
                'area_id' => 'Elige el área que usa la app.',
                'version' => 'Usa el formato v1.2.3.',
                'state' => 'Elige un estado de la lista.',
                'kind' => 'Elige el tipo de app.',
                'url' => 'Escribe la dirección completa, por ejemplo https://cartera.segurex.com.',
                'request_keys' => 'La solicitud GIA-999 no se puede vincular.',
            ]);
    }

    public function test_slug_format_and_area_of_another_company(): void
    {
        $this->actingAs($this->jhon)
            ->from(route('ai.products.create'))
            ->post(route('ai.products.store'), $this->payload(['slug' => 'Con Espacios', 'company_ids' => [$this->segurex->id], 'area_id' => $this->derivadosVentas->id]))
            ->assertSessionHasErrors([
                'slug' => 'Usa solo minúsculas, números y guiones.',
                'area_id' => 'Elige un área de la empresa del producto.',
            ]);
    }

    public function test_failed_store_renders_create_with_the_old_input(): void
    {
        $this->actingAs($this->jhon)
            ->from(route('ai.products.create'))
            ->followingRedirects()
            ->post(route('ai.products.store'), $this->payload(['version' => 'v1']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Products/Create', false)
                ->where('errors.version', 'Usa el formato v1.2.3.')
                ->where('form.name', 'Portafolio Derivados')
                ->where('form.company_ids', [$this->derivados->id])
                ->where('form.area_id', $this->derivadosVentas->id)
                ->where('form.owner_id', $this->paola->id)
                ->where('form.version', 'v1')
            );
    }

    public function test_edit_returns_exactly_the_contract_props_with_the_current_values(): void
    {
        $product = $this->product([
            'slug' => 'toma-pedidos',
            'name' => 'Toma Pedidos SAP',
            'description' => 'Pedidos a SAP B1.',
            'current_version' => 'v2.4.0',
            'stack' => 'Laravel',
            'hosting' => 'Servidor propio',
            'url' => null,
            'owner_user_id' => null,
        ]);
        $product->areas()->attach($this->compras->id, ['is_primary' => false]);
        $linked = $this->request(['product_id' => $product->id, 'title' => 'Descuentos']);

        $this->assertSame(
            $this->withShared(['areas', 'owners', 'hosts', 'requests', 'form', 'product']),
            $this->propKeys(route('ai.products.edit', $product)),
        );

        $this->actingAs($this->jhon)
            ->get(route('ai.products.edit', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Products/Edit', false)
                ->where('product', ['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP', 'company_id' => $this->segurex->id])
                ->where('form', [
                    'name' => 'Toma Pedidos SAP',
                    'slug' => 'toma-pedidos',
                    'company_ids' => [$this->segurex->id],
                    'area_id' => $this->ventas->id,
                    'owner_id' => null,
                    'description' => 'Pedidos a SAP B1.',
                    'request_keys' => [$linked->key],
                    'version' => 'v2.4.0',
                    'state' => 'production',
                    'kind' => 'web',
                    'stack' => 'Laravel',
                    'host' => 'Servidor propio',
                    'url' => '',
                ])
                ->where('requests', [['key' => $linked->key, 'title' => 'Descuentos', 'status' => 'received']])
                // A host outside the list is offered too, so editing never loses it.
                ->where('hosts', ['GoDaddy', 'Vercel', 'Equipo local', 'Servidor de SAP', 'Servidor propio'])
            );
    }

    public function test_update_changes_the_slug_keeps_other_areas_and_retires_without_deleting(): void
    {
        $product = $this->product(['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP', 'current_version' => 'v2.4.0']);
        $product->areas()->attach($this->compras->id, ['is_primary' => false]);
        $kept = $this->request(['product_id' => $product->id, 'title' => 'Se queda']);
        $removed = $this->request(['product_id' => $product->id, 'title' => 'Se quita']);

        $this->actingAs($this->jhon)
            ->put(route('ai.products.update', $product), $this->payload([
                'name' => 'Toma Pedidos',
                'slug' => 'toma-pedidos-sap',
                'company_ids' => [$this->segurex->id],
                'area_id' => $this->compras->id,
                'request_keys' => [$kept->key],
                'version' => 'v2.5.0',
                'state' => 'retired',
                'kind' => 'integration',
                'url' => '',
            ]))
            ->assertRedirect(route('ai.products.show', 'toma-pedidos-sap'))
            ->assertSessionHas('success', 'Cambios guardados.');

        $product->refresh();
        $this->assertSame('toma-pedidos-sap', $product->slug);
        $this->assertSame(ProductStatus::Retired, $product->status);
        $this->assertSame('integration', $product->kind);
        $this->assertNull($product->url);
        $this->assertNull($product->deleted_at);
        $this->assertSame($this->compras->id, $product->primaryArea()->id);
        // The old primary area was replaced; nothing else lost.
        $this->assertSame([$this->compras->id], $product->areas()->pluck('areas.id')->all());
        $this->assertSame($product->id, $kept->fresh()->product_id);
        $this->assertNull($removed->fresh()->product_id);
        // A new current version gets its version record.
        $this->assertSame(['v2.5.0'], $product->versions()->pluck('version')->all());

        $this->assertTrue(Activity::query()->where('subject_id', $product->id)->where('subject_type', $product->getMorphClass())->where('event', 'updated')->exists());
    }

    public function test_update_keeps_secondary_areas_of_the_selected_companies(): void
    {
        $product = $this->product(['slug' => 'toma-pedidos']);
        $product->areas()->attach($this->compras->id, ['is_primary' => false]);

        $this->actingAs($this->jhon)
            ->put(route('ai.products.update', $product), $this->payload([
                'slug' => 'toma-pedidos',
                'company_ids' => [$this->segurex->id],
                'area_id' => $this->ventas->id,
            ]))
            ->assertRedirect(route('ai.products.show', 'toma-pedidos'));

        $this->assertEqualsCanonicalizing([$this->ventas->id, $this->compras->id], $product->areas()->pluck('areas.id')->all());
        $this->assertSame($this->ventas->id, $product->primaryArea()->id);
    }

    public function test_update_may_keep_its_own_slug_and_there_is_no_delete_route(): void
    {
        $product = $this->product(['slug' => 'toma-pedidos']);

        $this->actingAs($this->jhon)
            ->put(route('ai.products.update', $product), $this->payload(['slug' => 'toma-pedidos', 'company_ids' => [$this->segurex->id], 'area_id' => $this->ventas->id]))
            ->assertSessionHasNoErrors();

        $this->assertFalse(app('router')->has('ai.products.destroy'));
        $this->actingAs($this->jhon)->delete('/ai/products/toma-pedidos')->assertStatus(405);
    }
}
