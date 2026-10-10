<?php

namespace Tests\Feature\Ai\Suggestions;

use App\Models\AiRequest;
use App\Models\AiSuggestion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Ai\Triage\TriageTestCase;

/**
 * AI suggestions (Stage D6, types/suggestions.ts): the provider is faked
 * at its HTTP endpoint (DeepSeek chat completions), as the Stage C tests.
 */
abstract class SuggestionsTestCase extends TriageTestCase
{
    use FakesAiProvider;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.deepseek.api_key' => 'test-deepseek-key']);
        Http::preventStrayRequests();
        Notification::fake();
    }

    /**
     * A full answer for a request batch.
     *
     * @return array<string, mixed>
     */
    protected function requestAnswer(array $duplicates = []): array
    {
        return [
            'summary' => 'Paola revisa en SAP los pedidos bloqueados por cartera y avisa a cada asesor. Pide un aviso automático.',
            'criteria' => [
                'El asesor ve un aviso en Toma Pedidos apenas SAP bloquea su pedido.',
                'El aviso llega también a su correo.',
            ],
            'size' => ['size' => 'M', 'reason' => 'El bloqueo ya se lee del Visualizador de cartera.'],
            'duplicates' => $duplicates,
        ];
    }

    /**
     * A request with enough text to be summarized.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function longRequest(array $attributes = []): AiRequest
    {
        return $this->request([
            'title' => 'Aviso de pedidos bloqueados por cartera',
            'problem' => 'Paola revisa en SAP dos veces al día los pedidos bloqueados por cartera y avisa a cada asesor por WhatsApp, uno por uno.',
            'current_process' => 'Exporta la lista de SAP a Excel, filtra por asesor y escribe a cada uno.',
            'expected_result' => 'Un aviso automático en Toma Pedidos y por correo, con el saldo vencido del cliente.',
            'systems' => ['Toma Pedidos SAP'],
            ...$attributes,
        ]);
    }

    /**
     * Generates the request batch through the route with the given answer.
     *
     * @param  array<string, mixed>|null  $answer
     */
    protected function suggest(AiRequest $request, ?array $answer = null): void
    {
        $this->provider($answer ?? $this->requestAnswer());

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.suggestions.store', $request))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    protected function kind(AiRequest $request, string $kind): AiSuggestion
    {
        return AiSuggestion::query()->where('subject_type', AiRequest::class)->where('subject_id', $request->id)->where('kind', $kind)->latest('id')->firstOrFail();
    }
}
