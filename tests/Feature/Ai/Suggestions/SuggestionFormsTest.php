<?php

namespace Tests\Feature\Ai\Suggestions;

use App\Enums\Ai\AcceptanceResult;
use App\Models\AiSuggestion;

/**
 * Jhon's own forms that may credit a suggestion: "Guardar criterios"
 * (`ai.requests.criteria.update`) and "Guardar evaluación"
 * (`ai.requests.evaluation.update` with `size_suggestion_id`).
 */
class SuggestionFormsTest extends SuggestionsTestCase
{
    public function test_criteria_update_syncs_rows_by_id_and_position(): void
    {
        $request = $this->longRequest();
        $keep = $request->criteria()->create(['position' => 1, 'text' => 'Filtrar por asesor.']);
        $drop = $request->criteria()->create(['position' => 2, 'text' => 'Exportar a PDF.']);

        $this->actingAs($this->jhon)
            ->put(route('ai.requests.criteria.update', $request), [
                'criteria' => [
                    ['id' => null, 'text' => '  El aviso llega por correo.  '],
                    ['id' => $keep->id, 'text' => 'Filtrar por asesor y zona.'],
                ],
                'suggestion_id' => null,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Criterios guardados.');

        $rows = $request->criteria()->get();
        $this->assertSame(['El aviso llega por correo.', 'Filtrar por asesor y zona.'], $rows->pluck('text')->all());
        $this->assertSame([1, 2], $rows->pluck('position')->all());
        $this->assertSame($keep->id, $rows[1]->id);
        $this->assertNull($drop->fresh());
    }

    public function test_criteria_update_marks_the_criteria_suggestion_used(): void
    {
        $request = $this->longRequest();
        $this->suggest($request);
        $suggestion = $this->kind($request, 'criteria');

        $this->actingAs($this->jhon)
            ->put(route('ai.requests.criteria.update', $request), [
                'criteria' => [['id' => null, 'text' => 'El aviso llega también a su correo.']],
                'suggestion_id' => $suggestion->id,
            ])
            ->assertSessionHasNoErrors();

        $suggestion->refresh();
        $this->assertSame('accepted', $suggestion->state);
        $this->assertSame($this->jhon->id, $suggestion->accepted_by_id);
        $this->assertSame(['items' => ['El aviso llega también a su correo.']], $suggestion->accepted_value);

        // A suggestion of another kind or request is ignored.
        $size = $this->kind($request, 'size');
        $this->actingAs($this->jhon)
            ->put(route('ai.requests.criteria.update', $request), ['criteria' => [['id' => null, 'text' => 'Otro']], 'suggestion_id' => $size->id])
            ->assertSessionHasErrors('suggestion_id');
        $this->assertSame('ready', $size->fresh()->state);
    }

    public function test_criteria_update_validation(): void
    {
        $request = $this->longRequest();
        $other = $this->longRequest();
        $foreign = $other->criteria()->create(['position' => 1, 'text' => 'Ajeno']);
        $validated = $request->criteria()->create(['position' => 1, 'text' => 'Ya validado', 'result' => AcceptanceResult::Pass, 'validated_at' => now()]);

        $put = fn (array $body) => $this->actingAs($this->jhon)->put(route('ai.requests.criteria.update', $request), $body + ['suggestion_id' => null]);

        $put(['criteria' => [['id' => null, 'text' => '']]])->assertSessionHasErrors('criteria.0.text');
        $put(['criteria' => [['id' => null, 'text' => str_repeat('a', 501)]]])->assertSessionHasErrors('criteria.0.text');
        $put(['criteria' => [['id' => $foreign->id, 'text' => 'Mío']]])->assertSessionHasErrors('criteria');
        $put(['criteria' => []])->assertSessionHasErrors('criteria');
        $put(['criteria' => [['id' => $validated->id, 'text' => 'Cambiado']]])->assertSessionHasErrors('criteria');

        $this->assertSame('Ajeno', $foreign->fresh()->text);
        $this->assertSame('Ya validado', $validated->fresh()->text);

        $this->actingAs($this->paola)->put(route('ai.requests.criteria.update', $request), ['criteria' => [['id' => null, 'text' => 'x']], 'suggestion_id' => null])->assertForbidden();
    }

    public function test_evaluation_credits_the_size_suggestion_only_when_the_saved_size_matches(): void
    {
        $request = $this->longRequest();
        $this->suggest($request);
        $size = $this->kind($request, 'size');
        $body = ['urgency' => 'high', 'risk' => 'low', 'priority_suggested' => null, 'priority_final' => null];

        $this->actingAs($this->jhon)
            ->put(route('ai.requests.evaluation.update', $request), [...$body, 'size' => 'L', 'size_suggestion_id' => $size->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('ready', $size->fresh()->state);
        $this->assertSame('L', $request->fresh()->size->value);

        $this->actingAs($this->jhon)
            ->put(route('ai.requests.evaluation.update', $request), [...$body, 'size' => 'M', 'size_suggestion_id' => $size->id])
            ->assertSessionHasNoErrors();
        $size->refresh();
        $this->assertSame('accepted', $size->state);
        $this->assertSame(['size' => 'M'], $size->accepted_value);
        $this->assertSame($this->jhon->id, $size->accepted_by_id);

        // Without the id nothing is credited; a bad id is a validation error.
        $this->actingAs($this->jhon)->put(route('ai.requests.evaluation.update', $request), [...$body, 'size' => 'M', 'size_suggestion_id' => 'abc'])->assertSessionHasErrors('size_suggestion_id');
        $this->actingAs($this->jhon)->put(route('ai.requests.evaluation.update', $request), [...$body, 'size' => 'M'])->assertSessionHasNoErrors();
        $this->assertSame(1, AiSuggestion::query()->where('state', 'accepted')->count());
    }
}
