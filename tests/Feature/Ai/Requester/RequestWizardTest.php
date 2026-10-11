<?php

namespace Tests\Feature\Ai\Requester;

use App\Enums\Ai\RequestStatus;
use App\Enums\Ai\RequestType;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AiRequest;
use App\Models\AiRequestDraft;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * `Ai/Requests/Create` (RequestsCreateProps), the autosaved draft, the
 * similar-requests partial reload, `ai.requests.store` and `Submitted`.
 */
class RequestWizardTest extends RequesterTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'type' => 'new',
            'product_slug' => null,
            'problem' => 'Cuando un pedido queda bloqueado por cartera el asesor no se entera. Lo descubre tarde.',
            'hours_per_week' => 6,
            'people' => 8,
            'expected_result' => 'Que llegue un aviso al asesor apenas se bloquee el pedido.',
            'success_criteria' => "El asesor recibe el aviso en menos de 5 minutos\nEl aviso dice el cliente y el valor",
            'systems' => ['SAP Business One', 'Correo', 'Telegram'],
            'systems_notes' => 'El bloqueo se ve en SAP.',
            'urgency' => 'high',
            'deadline' => null,
            'deadline_reason' => '',
            'area_id' => $this->ventas->id,
        ], $overrides);
    }

    public function test_create_page_has_exactly_the_contract_props(): void
    {
        $this->product();
        $this->product('portafolio', 'Portafolio de productos', [$this->derivados]);

        $this->actingAs($this->paola)
            ->get(route('ai.requests.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Requests/Create', false)
                ->where('product', null)
                ->has('types', 4)
                ->has('types.0', fn (Assert $type) => $type->where('value', 'new')->has('title')->has('description'))
                ->where('apps', [['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP', 'version' => 'v1.8.2']])
                ->where('systems_options', fn ($options) => collect($options)->contains('SAP Business One')
                    && collect($options)->contains('Toma Pedidos SAP')
                    && ! collect($options)->contains('Portafolio de productos'))
                ->where('area_suggestion', ['id' => $this->ventas->id, 'name' => 'Ventas'])
                ->where('areas', [
                    ['id' => $this->compras->id, 'name' => 'Compras'],
                    ['id' => $this->ventas->id, 'name' => 'Ventas'],
                ])
                ->where('max_attachment_mb', 10)
                ->where('similar', [])
                ->where('today', now('America/Bogota')->toDateString())
                ->where('draft', null)
                ->where('draft_saved_at', null)
                ->missing('initial_step')
            );

        $this->assertEqualsCanonicalizing(
            [...self::SHARED, 'product', 'types', 'apps', 'systems_options', 'area_suggestion', 'areas', 'max_attachment_mb', 'similar', 'today', 'draft', 'draft_saved_at'],
            $this->propKeys(route('ai.requests.create')),
        );
    }

    public function test_producto_preselects_an_app_of_the_requester_company(): void
    {
        $this->product();
        $this->product('portafolio', 'Portafolio de productos', [$this->derivados]);

        $this->actingAs($this->paola)
            ->get(route('ai.requests.create', ['producto' => 'toma-pedidos']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product', ['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP', 'version' => 'v1.8.2'])
            );

        // Another company's app (or an unknown slug) is ignored.
        $this->actingAs($this->paola)
            ->get(route('ai.requests.create', ['producto' => 'portafolio']))
            ->assertInertia(fn (Assert $page) => $page->where('product', null));
    }

    public function test_similar_requests_arrive_on_the_partial_reload_with_q(): void
    {
        $match = $this->request([
            'title' => 'Alerta de pedidos bloqueados por cartera',
            'problem' => 'Los asesores no saben cuando cartera bloquea un pedido.',
            'requester_id' => $this->juan->id,
            'created_by_id' => $this->juan->id,
            'votes_count' => 4,
        ]);
        $this->request([
            'company_id' => $this->derivados->id,
            'area_id' => $this->derivadosVentas->id,
            'requester_id' => $this->derivadosUser->id,
            'created_by_id' => $this->derivadosUser->id,
            'title' => 'Pedidos bloqueados por cartera en Derivados',
        ]);

        $this->actingAs($this->paola)
            ->get(route('ai.requests.create', ['q' => 'pedido bloqueado por cartera sin aviso al asesor']), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $this->inertiaVersion(),
                'X-Inertia-Partial-Component' => 'Ai/Requests/Create',
                'X-Inertia-Partial-Data' => 'similar',
            ])
            ->assertOk()
            ->assertJsonPath('props.similar.0.key', $match->key)
            ->assertJsonPath('props.similar.0.votes', 4)
            ->assertJsonCount(1, 'props.similar')
            ->assertJsonMissingPath('props.apps');
    }

    public function test_short_queries_find_nothing(): void
    {
        $this->request(['title' => 'Pedidos bloqueados por cartera']);

        $this->actingAs($this->paola)
            ->get(route('ai.requests.create', ['q' => 'pedidos']))
            ->assertInertia(fn (Assert $page) => $page->where('similar', []));
    }

    public function test_the_draft_autosaves_and_is_offered_again(): void
    {
        $this->actingAs($this->paola)
            ->from(route('ai.requests.create'))
            ->put(route('ai.requests.draft.update'), $this->payload(['type' => 'bug', 'product_slug' => 'toma-pedidos', 'urgency' => null]))
            ->assertRedirect(route('ai.requests.create'));

        $draft = AiRequestDraft::query()->where('user_id', $this->paola->id)->sole();
        $this->assertSame('bug', $draft->data['type']);

        $this->actingAs($this->paola)
            ->get(route('ai.requests.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('draft.type', 'bug')
                ->where('draft.product_slug', 'toma-pedidos')
                ->where('draft.problem', $this->payload()['problem'])
                ->where('draft.area_id', $this->ventas->id)
                ->missing('draft.attachments')
                ->where('draft_saved_at', $draft->saved_at->toIso8601String())
                ->where('initial_step', 6)
            );
    }

    public function test_the_draft_ignores_unknown_fields(): void
    {
        $this->actingAs($this->paola)
            ->put(route('ai.requests.draft.update'), ['problem' => 'Algo', 'status' => 'approved', 'company_id' => 99])
            ->assertRedirect();

        $this->assertSame(['problem' => 'Algo'], array_filter(
            AiRequestDraft::query()->sole()->data,
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        ));
    }

    public function test_store_files_the_request_and_shows_submitted(): void
    {
        Storage::fake('local');
        $this->product();
        $this->actingAs($this->paola)->put(route('ai.requests.draft.update'), ['problem' => 'borrador']);

        $response = $this->actingAs($this->paola)->post(route('ai.requests.store'), [
            ...$this->payload(['type' => 'improvement', 'product_slug' => 'toma-pedidos', 'deadline' => now('America/Bogota')->addDays(10)->toDateString(), 'deadline_reason' => 'Cambian las listas']),
            'attachments' => [UploadedFile::fake()->image('bloqueo.png'), UploadedFile::fake()->create('pedidos.pdf', 200, 'application/pdf')],
        ]);

        $request = AiRequest::query()->sole();
        $response->assertRedirect(route('ai.requests.submitted', $request));

        $this->assertSame(RequestType::Improvement, $request->type);
        $this->assertSame(RequestStatus::Received, $request->status);
        $this->assertSame($this->paola->id, $request->requester_id);
        $this->assertSame('v1.8.2', $request->product_version);
        $this->assertSame(8, $request->people_count);
        $this->assertSame(['SAP Business One', 'Correo', 'Telegram'], $request->systems);
        $this->assertSame(['El asesor recibe el aviso en menos de 5 minutos', 'El aviso dice el cliente y el valor'], $request->criteria()->pluck('text')->all());
        $this->assertCount(2, $request->attachments);
        Storage::disk('local')->assertExists($request->attachments->first()->path);
        $this->assertTrue($request->followers()->whereKey($this->paola->id)->exists());
        $this->assertSame(0, AiRequestDraft::query()->count());

        $this->actingAs($this->paola)
            ->get(route('ai.requests.submitted', $request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Requests/Submitted', false)
                ->where('request', ['key' => $request->key, 'title' => $request->title])
                ->where('first_response_due_at', $request->first_response_due_at->toIso8601String())
                ->where('telegram_linked', false)
            );

        $this->assertEqualsCanonicalizing(
            [...self::SHARED, 'request', 'first_response_due_at', 'telegram_linked'],
            $this->propKeys(route('ai.requests.submitted', $request)),
        );
    }

    public function test_store_errors_use_the_wizard_field_keys(): void
    {
        $this->product('portafolio', 'Portafolio de productos', [$this->derivados]);

        $this->actingAs($this->paola)
            ->from(route('ai.requests.create'))
            ->post(route('ai.requests.store'), $this->payload([
                'type' => 'bug',
                'product_slug' => 'portafolio',
                'problem' => 'Muy corto',
                'hours_per_week' => 90,
                'people' => 0,
                'expected_result' => '',
                'urgency' => null,
                'deadline' => now('America/Bogota')->toDateString(),
                'deadline_reason' => '',
                'area_id' => $this->derivadosVentas->id,
            ]))
            ->assertRedirect(route('ai.requests.create'))
            ->assertSessionHasErrors([
                'product_slug', 'problem', 'hours_per_week', 'people', 'expected_result', 'urgency', 'deadline', 'deadline_reason',
            ]);

        // The area must be of the requester's company (domain rule).
        $this->actingAs($this->paola)
            ->post(route('ai.requests.store'), $this->payload(['area_id' => $this->derivadosVentas->id]))
            ->assertSessionHasErrors(['area_id']);

        $this->assertSame(0, AiRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_bad_attachments_are_refused_per_file(): void
    {
        Storage::fake('local');

        $this->actingAs($this->paola)
            ->post(route('ai.requests.store'), [
                ...$this->payload(),
                'attachments' => [UploadedFile::fake()->create('precios.xlsx', 14 * 1024, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')],
            ])
            ->assertSessionHasErrors(['attachments.0']);

        $this->assertSame(0, AiRequest::query()->count());
    }

    public function test_an_idea_may_skip_the_time_step(): void
    {
        $this->actingAs($this->paola)
            ->post(route('ai.requests.store'), $this->payload(['type' => 'idea', 'hours_per_week' => null, 'people' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull(AiRequest::query()->sole()->hours_per_week);
    }

    public function test_plus_one_from_the_wizard_and_its_removal(): void
    {
        $similar = $this->request(['requester_id' => $this->juan->id, 'created_by_id' => $this->juan->id]);

        $this->actingAs($this->paola)
            ->from(route('ai.requests.create'))
            ->post(route('ai.requests.vote', $similar))
            ->assertRedirect(route('ai.requests.create'));

        $similar->refresh();
        $this->assertSame(1, $similar->votes_count);
        $this->assertTrue($similar->followers()->whereKey($this->paola->id)->exists());

        $this->actingAs($this->paola)->delete(route('ai.requests.vote', $similar))->assertRedirect();
        $this->assertSame(0, $similar->refresh()->votes_count);

        // Nobody +1's their own request.
        $this->actingAs($this->juan)->post(route('ai.requests.vote', $similar))->assertForbidden();
    }

    public function test_wizard_needs_ai_requests_create_and_hides_other_companies(): void
    {
        $nobody = User::factory()->create(['company_id' => $this->segurex->id]);

        $this->actingAs($nobody)->get(route('ai.requests.create'))->assertForbidden();
        $this->actingAs($nobody)->post(route('ai.requests.store'), $this->payload())->assertForbidden();
        $this->actingAs($nobody)->put(route('ai.requests.draft.update'), [])->assertForbidden();

        $request = $this->request();
        $this->actingAs($this->derivadosUser)->get(route('ai.requests.submitted', $request))->assertNotFound();
        $this->actingAs($this->juan)->get(route('ai.requests.submitted', $request))->assertForbidden();
    }

    private function inertiaVersion(): string
    {
        return (string) app(HandleInertiaRequests::class)->version(request());
    }
}
