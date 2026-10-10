<?php

namespace Tests\Feature\Ai\Knowledge;

use App\Domain\Ai\TriagePeople;
use App\Models\AiProductIncident;
use App\Models\User;
use App\Notifications\ProductIncidentRegistered;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * Incident drawer on `Ai/Products/Show` (C-IncidentForm,
 * types/pages/ai-knowledge.ts `incident_form`).
 */
class ProductIncidentsTest extends KnowledgeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:40', 'America/Bogota'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Los pedidos no sincronizan con SAP B1',
            'started_at' => '2026-10-07T08:10',
            'impact' => 'high',
            'description' => 'Los pedidos quedan pendientes de sincronizar.',
            'status' => 'open',
            'ended_at' => null,
            'resolution' => null,
            'notify_ids' => [$this->jhon->id],
            'notify_owner' => true,
        ], $overrides);
    }

    /**
     * @return list<int>
     */
    private function teamIds(): array
    {
        return app(TriagePeople::class)->team()->modelKeys();
    }

    public function test_show_carries_the_closed_drawer_with_its_defaults(): void
    {
        $product = $this->product(['owner_user_id' => $this->paola->id]);

        $this->assertSame(
            $this->withShared(['product', 'health', 'incidents', 'hours_note', 'cost_note', 'request_notes', 'incident_form', 'github', 'repo_link', 'now']),
            $this->propKeys(route('ai.products.show', $product)),
        );

        $this->actingAs($this->jhon)
            ->get(route('ai.products.show', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('incident_form.open', false)
                ->where('incident_form.defaults', [
                    'title' => '',
                    'started_on' => '2026-10-07',
                    'started_time' => '09:40',
                    'impact' => null,
                    'description' => '',
                    'status' => 'open',
                    'ended_on' => '',
                    'ended_time' => '',
                    'resolution' => '',
                    'notify_ids' => $this->teamIds(),
                    'notify_owner' => true,
                ])
                ->has('incident_form.notify_people', count($this->teamIds()))
                ->where('incident_form.owner_label', 'Paola Ríos')
                ->where('incident_form.reporter.id', $this->jhon->id)
                ->where('incident_form.reporter.name', 'Jhon Castro')
            );
    }

    public function test_owner_placeholder_and_no_owner_notice_without_an_owner_user(): void
    {
        $product = $this->product(['owner_user_id' => null, 'owner_name' => null]);

        $this->actingAs($this->jhon)
            ->get(route('ai.products.show', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->where('incident_form.owner_label', '[DUEÑO]')
                ->where('incident_form.defaults.notify_owner', false)
            );
    }

    public function test_the_deep_link_renders_show_with_the_drawer_open(): void
    {
        $product = $this->product();

        $this->actingAs($this->jhon)
            ->get(route('ai.products.incidents.create', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Products/Show', false)
                ->where('product.slug', $product->slug)
                ->where('incident_form.open', true)
            );

        $this->actingAs($this->paola)->get(route('ai.products.incidents.create', $product))->assertForbidden();
    }

    public function test_store_registers_the_incident_and_notifies(): void
    {
        Notification::fake();
        $product = $this->product(['owner_user_id' => $this->paola->id]);

        $this->actingAs($this->jhon)
            ->post(route('ai.products.incidents.store', $product), $this->payload())
            ->assertRedirect(route('ai.products.show', $product))
            ->assertSessionHas('success', 'Incidente registrado.');

        /** @var AiProductIncident $incident */
        $incident = $product->incidents()->firstOrFail();
        $this->assertSame('Los pedidos no sincronizan con SAP B1', $incident->title);
        $this->assertSame('2026-10-07 08:10', $incident->started_at->timezone('America/Bogota')->format('Y-m-d H:i'));
        $this->assertNull($incident->resolved_at);
        $this->assertSame('high', $incident->impact);
        $this->assertSame('manual', $incident->origin);
        $this->assertSame($this->jhon->id, $incident->reported_by_id);

        Notification::assertSentTo([$this->jhon, $this->paola], ProductIncidentRegistered::class);
        Notification::assertNotSentTo($this->juan, ProductIncidentRegistered::class);
        $this->assertTrue(Activity::query()->where('event', 'product_incident')->where('subject_id', $product->id)->exists());

        $this->actingAs($this->jhon)
            ->get(route('ai.products.show', $product))
            ->assertInertia(fn (Assert $page) => $page
                ->has('incidents', 1)
                ->where('incidents.0.title', 'Los pedidos no sincronizan con SAP B1')
                ->where('incidents.0.resolved_at', null)
                ->where('incidents.0.reported_by.name', 'Jhon Castro')
            );
    }

    public function test_a_resolved_incident_needs_end_and_resolution(): void
    {
        $product = $this->product();

        $this->actingAs($this->jhon)
            ->post(route('ai.products.incidents.store', $product), $this->payload(['status' => 'resolved']))
            ->assertRedirect(route('ai.products.incidents.create', $product))
            ->assertSessionHasErrors([
                'ended_at' => 'Escribe cuándo terminó el incidente.',
                'resolution' => 'Cuenta qué lo causó y cómo se solucionó.',
            ]);

        $this->actingAs($this->jhon)
            ->post(route('ai.products.incidents.store', $product), $this->payload([
                'status' => 'resolved',
                'ended_at' => '2026-10-07T09:25',
                'resolution' => 'Se renovó la sesión del Service Layer.',
                'notify_ids' => [],
                'notify_owner' => false,
            ]))
            ->assertRedirect(route('ai.products.show', $product));

        $incident = $product->incidents()->firstOrFail();
        $this->assertSame('2026-10-07 09:25', $incident->resolved_at->timezone('America/Bogota')->format('Y-m-d H:i'));
        $this->assertSame('Se renovó la sesión del Service Layer.', $incident->resolution);
    }

    public function test_validation_messages_use_the_contract_keys(): void
    {
        $product = $this->product();
        $outsider = User::factory()->create();

        $this->actingAs($this->jhon)
            ->post(route('ai.products.incidents.store', $product), $this->payload([
                'title' => '',
                'started_at' => '2026-10-08T10:00',
                'impact' => null,
                'description' => '',
                'status' => 'resolved',
                'ended_at' => '2026-10-07T07:00',
                'resolution' => 'x',
                'notify_ids' => [$outsider->id],
            ]))
            ->assertSessionHasErrors([
                'title' => 'Escribe un título corto para el incidente.',
                'started_at' => 'La hora de inicio no puede ser futura.',
                'impact' => 'Elige el impacto.',
                'description' => 'Cuenta qué pasa.',
                'notify_ids' => 'Elige personas de la lista.',
            ]);

        $this->actingAs($this->jhon)
            ->post(route('ai.products.incidents.store', $product), $this->payload([
                'status' => 'resolved',
                'ended_at' => '2026-10-07T07:00',
                'resolution' => 'x',
            ]))
            ->assertSessionHasErrors(['ended_at' => 'El final no puede ser antes del inicio.']);

        $this->assertSame(0, $product->incidents()->count());
    }

    public function test_a_failed_store_reopens_the_drawer_with_the_errors_and_old_input(): void
    {
        $product = $this->product();

        $this->actingAs($this->jhon)
            ->followingRedirects()
            ->post(route('ai.products.incidents.store', $product), $this->payload(['status' => 'resolved', 'impact' => 'critical']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Products/Show', false)
                ->where('incident_form.open', true)
                ->where('errors.ended_at', 'Escribe cuándo terminó el incidente.')
                ->where('incident_form.defaults.title', 'Los pedidos no sincronizan con SAP B1')
                ->where('incident_form.defaults.started_on', '2026-10-07')
                ->where('incident_form.defaults.started_time', '08:10')
                ->where('incident_form.defaults.impact', 'critical')
                ->where('incident_form.defaults.status', 'resolved')
                ->where('incident_form.defaults.notify_ids', [$this->jhon->id])
                ->where('incident_form.defaults.notify_owner', true)
            );
    }

    public function test_store_needs_ai_manage(): void
    {
        $product = $this->product();

        $this->actingAs($this->paola)
            ->post(route('ai.products.incidents.store', $product), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, $product->incidents()->count());
    }
}
