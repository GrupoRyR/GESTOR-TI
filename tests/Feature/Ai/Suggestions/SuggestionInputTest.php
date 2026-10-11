<?php

namespace Tests\Feature\Ai\Suggestions;

use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Models\AiCostRate;
use App\Models\AiEstimate;
use App\Models\AiProduct;
use App\Models\AiRequestAttachment;

/**
 * What the AI receives for a request (App\Domain\Suggestions\SuggestionInput):
 * title, problem, current process, expected result and app, plus keys and
 * titles of open requests of the same company for duplicates. Nothing else.
 */
class SuggestionInputTest extends SuggestionsTestCase
{
    public function test_only_whitelisted_fields_reach_the_provider(): void
    {
        $product = AiProduct::factory()->create(['name' => 'Toma Pedidos SAP', 'slug' => 'toma-pedidos']);
        $request = $this->longRequest([
            'product_id' => $product->id,
            'who_suffers' => 'quien-sufre-reservado',
            'hours_per_week' => 37.5,
            'people_count' => 13,
            'success_criteria' => 'criterio-de-exito-reservado',
            'systems_notes' => 'nota-de-sistemas-reservada',
        ]);
        $request->comments()->create(['user_id' => $this->jhon->id, 'body' => 'Nota interna: el proveedor cobra demasiado', 'visibility' => CommentVisibility::Internal]);
        $request->comments()->create(['user_id' => $this->paola->id, 'body' => 'comentario-publico-del-solicitante', 'visibility' => CommentVisibility::Public]);
        AiRequestAttachment::factory()->create(['ai_request_id' => $request->id, 'original_name' => 'adjunto-reservado.xlsx']);
        AiEstimate::create(['ai_request_id' => $request->id, 'hours_total' => 62, 'external_costs' => 1_234_321, 'total_cost' => 8_765_432, 'due_date' => '2026-12-01']);
        AiCostRate::create(['user_id' => $this->jhon->id, 'rate' => 187_654, 'effective_from' => '2026-01-01']);
        $this->ventas->forceFill(['hour_value' => 55_555])->save();

        $open = $this->request(['title' => 'Alerta de pedidos bloqueados por cartera']);
        $withMoney = $this->request(['title' => 'Comprar licencia de $ 2.400.000']);
        $closed = $this->request(['title' => 'Cerrada-no-se-ofrece', 'status' => RequestStatus::Closed]);
        $foreign = $this->request(['title' => 'De-otra-empresa', 'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'requester_id' => $this->derivadosUser->id]);

        $this->suggest($request);

        $prompt = $this->lastPrompt();
        foreach ([$request->title, $request->problem, $request->current_process, $request->expected_result, 'Toma Pedidos SAP', $open->key, $open->title] as $allowed) {
            $this->assertStringContainsString((string) $allowed, $prompt);
        }

        $raw = $this->sentRaw();
        foreach ([
            'quien-sufre-reservado', '37.5', 'criterio-de-exito-reservado', 'nota-de-sistemas-reservada', 'el proveedor cobra demasiado',
            'comentario-publico-del-solicitante', 'adjunto-reservado', '1234321', '8765432', '187654', '55555', '2.400.000',
            $this->paola->name, (string) $this->paola->email, $this->jhon->name, $closed->title, $foreign->title, $withMoney->key,
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, "Leaked: {$forbidden}");
        }
    }

    public function test_unsafe_free_text_is_dropped_whole(): void
    {
        $request = $this->longRequest([
            'problem' => 'Para entrar a SAP uso password=Sup3rS3creto99 y reviso los pedidos bloqueados por cartera uno por uno cada mañana.',
        ]);
        $request->comments()->create(['user_id' => $this->jhon->id, 'body' => 'cliente moroso de la zona norte', 'visibility' => CommentVisibility::Internal]);
        $request->forceFill(['current_process' => 'Lo de siempre: cliente moroso de la zona norte, se llama a cada asesor.'])->save();

        $this->suggest($request);

        $raw = $this->sentRaw();
        $this->assertStringNotContainsString('Sup3rS3creto99', $raw);
        $this->assertStringNotContainsString('zona norte', $raw);
        $this->assertStringContainsString($request->expected_result, $this->lastPrompt());
    }
}
