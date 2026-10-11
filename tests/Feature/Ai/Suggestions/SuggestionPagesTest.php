<?php

namespace Tests\Feature\Ai\Suggestions;

use App\Enums\Ai\RequestStatus;
use App\Models\AiInteraction;
use App\Models\AiSuggestion;
use App\Support\Settings;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Props of the IT view of `Ai/Requests/Show` (`suggestions`,
 * `ai_availability`, `ai_usage`, `merge_into`) and `Ai/Requests/Compare`.
 */
class SuggestionPagesTest extends SuggestionsTestCase
{
    public function test_it_view_sends_the_suggestions_of_the_request(): void
    {
        $request = $this->longRequest();
        $gia121 = $this->request(['title' => 'Alerta de pedidos bloqueados por cartera']);
        $this->suggest($request, $this->requestAnswer([['key' => $gia121->key, 'level' => 'alto', 'reason_summary' => 'mismo problema', 'reasons' => ['a'], 'differences' => ['b']]]));
        $this->actingAs($this->jhon)->post(route('ai.suggestions.accept', $this->kind($request, 'summary')), ['value' => 'Resumen de Jhon.']);

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('ai_availability', 'ready')
                ->where('ai_usage', null)
                ->where('merge_into', null)
                ->where('suggestions.summary_text', 'Resumen de Jhon.')
                ->where('suggestions.summary.state', 'accepted')
                ->where('suggestions.summary.provider', 'DeepSeek')
                ->where('suggestions.summary.accepted.by', 'Jhon Castro')
                ->where('suggestions.summary.discarded', null)
                ->where('suggestions.summary.stale', null)
                ->where('suggestions.summary.error', null)
                ->where('suggestions.criteria.kind', 'criteria')
                ->where('suggestions.criteria.payload.items.0.id', 1)
                ->where('suggestions.size.payload.size', 'M')
                ->has('suggestions.duplicates', 1)
                ->where('suggestions.duplicates.0.payload.request.key', $gia121->key)
                ->where('suggestions.duplicates.0.payload.reasons', ['a'])
                ->has('suggestions.summary', fn (Assert $summary) => $summary
                    ->hasAll(['id', 'kind', 'state', 'provider', 'created_at', 'payload', 'accepted', 'discarded', 'stale', 'error'])
                )
            );
    }

    public function test_none_yet_is_null_and_generating_suggestions_have_no_payload(): void
    {
        $request = $this->longRequest();

        $this->actingAs($this->jhon)->get(route('ai.requests.show', $request))->assertInertia(fn (Assert $page) => $page->where('suggestions', null));

        AiSuggestion::factory()->for($request, 'subject')->create(['kind' => 'summary', 'state' => 'generating', 'payload' => null]);
        AiSuggestion::factory()->for($request, 'subject')->create(['kind' => 'size', 'state' => 'cancelled', 'payload' => null]);

        $this->actingAs($this->jhon)->get(route('ai.requests.show', $request))->assertInertia(fn (Assert $page) => $page
            ->where('suggestions.summary.state', 'generating')
            ->where('suggestions.summary.payload', null)
            ->where('suggestions.size', null)
            ->where('suggestions.criteria', null)
            ->where('suggestions.duplicates', [])
            ->where('suggestions.summary_text', null)
        );
    }

    public function test_availability_states(): void
    {
        $request = $this->longRequest();
        AiSuggestion::factory()->for($request, 'subject')->create(['kind' => 'summary', 'state' => 'ready', 'payload' => ['text' => 'Resumen']]);

        config(['services.deepseek.api_key' => null]);
        $this->actingAs($this->jhon)->get(route('ai.requests.show', $request))->assertInertia(fn (Assert $page) => $page
            ->where('ai_availability', 'off_no_key')
            ->where('ai_usage', null)
            ->where('suggestions', null)
        );

        config(['services.deepseek.api_key' => 'test-deepseek-key']);
        app(Settings::class)->set('ai.monthly_cost_cap_usd', 10);
        AiInteraction::query()->create(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'feature' => 'assistant', 'status' => 'ok', 'cost' => '10.5']);
        $this->actingAs($this->jhon)->get(route('ai.requests.show', $request))->assertInertia(fn (Assert $page) => $page
            ->where('ai_availability', 'off_cap')
            ->where('ai_usage.spent_label', 'US$ 10,50')
            ->where('suggestions', null)
        );
    }

    public function test_the_requester_view_never_has_suggestions(): void
    {
        $request = $this->longRequest();
        AiSuggestion::factory()->for($request, 'subject')->create(['kind' => 'summary', 'state' => 'ready', 'payload' => ['text' => 'Resumen secreto de TI']]);

        $this->actingAs($this->paola)
            ->get(route('ai.requests.show', $request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'requester')
                ->missing('suggestions')
                ->missing('ai_availability')
                ->missing('ai_usage')
                ->missing('merge_into')
            )
            ->assertDontSee('Resumen secreto de TI');
    }

    public function test_suggestions_reload_alone(): void
    {
        $request = $this->longRequest();
        $this->suggest($request);

        $this->actingAs($this->jhon)
            ->withHeaders($this->partial('Ai/Requests/Show', 'suggestions'))
            ->get(route('ai.requests.show', $request))
            ->assertOk()
            ->assertJsonPath('props.suggestions.summary.state', 'ready')
            ->assertJsonMissingPath('props.request')
            ->assertJsonMissingPath('props.duplicates');
        $this->flushHeaders();
    }

    public function test_merge_into_comes_from_the_unir_query(): void
    {
        $request = $this->longRequest();
        $gia121 = $this->request(['title' => 'Alerta de pedidos']);
        $closed = $this->request(['status' => RequestStatus::Closed]);
        $other = $this->request(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'requester_id' => $this->derivadosUser->id]);

        $prop = fn (string $key) => $this->actingAs($this->jhon)->withSession(['current_company_id' => $this->segurex->id])
            ->get(route('ai.requests.show', [$request, 'unir' => $key]))->viewData('page')['props']['merge_into'];

        $this->assertSame($gia121->key, $prop(strtolower($gia121->key)));
        $this->assertNull($prop($request->key));
        $this->assertNull($prop($closed->key));
        $this->assertNull($prop($other->key));
        $this->assertNull($prop('GIA-9999'));
    }

    public function test_compare_page_puts_both_requests_side_by_side(): void
    {
        $request = $this->longRequest();
        $gia121 = $this->request(['title' => 'Alerta de pedidos bloqueados', 'problem' => 'Los asesores no saben cuándo SAP frena un pedido.', 'expected_result' => 'Alerta en Toma Pedidos.', 'systems' => ['Toma Pedidos SAP']]);
        $gia121->comments()->create(['user_id' => $this->jhon->id, 'body' => 'Nota interna de GIA-121', 'visibility' => 'internal']);
        $this->suggest($request, $this->requestAnswer([['key' => $gia121->key, 'level' => 'alto', 'reason_summary' => 'mismo problema', 'reasons' => ['Mismo problema.'], 'differences' => []]]));
        $duplicate = $this->kind($request, 'duplicate');

        $response = $this->actingAs($this->jhon)
            ->get(route('ai.requests.compare', ['request' => $request, 'other' => $gia121]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Requests/Compare', false)
                ->where('current.request.key', $request->key)
                ->where('current.problem', $request->problem)
                ->where('current.systems', ['Toma Pedidos SAP'])
                ->where('other.request.key', $gia121->key)
                ->where('other.expected_result', 'Alerta en Toma Pedidos.')
                ->where('suggestion.id', $duplicate->id)
                ->where('suggestion.payload.reasons', ['Mismo problema.'])
                ->has('now')
            );
        $props = array_keys($response->viewData('page')['props']);
        sort($props);
        $this->assertSame($this->withShared(['current', 'other', 'suggestion', 'now']), $props);
        $response->assertDontSee('Nota interna de GIA-121');

        // No AI suggestion links them: 404. Requesters: 403.
        $third = $this->request();
        $this->actingAs($this->jhon)->get(route('ai.requests.compare', ['request' => $request, 'other' => $third]))->assertNotFound();
        $this->actingAs($this->paola)->get(route('ai.requests.compare', ['request' => $request, 'other' => $gia121]))->assertForbidden();
    }
}
