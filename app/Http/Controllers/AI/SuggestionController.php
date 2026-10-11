<?php

namespace App\Http\Controllers\AI;

use App\Domain\Suggestions\Suggestions;
use App\Http\Controllers\Controller;
use App\Models\AcceptanceCriterion;
use App\Models\AiRequest;
use App\Models\AiSuggestion;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\Presenters\SuggestionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI suggestions (Stage D6, types/pages/ai-triage.ts header), `ai.manage`.
 * Every action re-checks the subject (App\Domain\Suggestions\Suggestions::subject)
 * and answers back to the page (the request page reloads `only: ['suggestions']`).
 *
 * - `ai.requests.suggestions.store` POST "Sugerir con IA"
 * - `ai.suggestions.accept`  POST `{ value? }`  "Usar" (summary: Jhon's edited text)
 * - `ai.suggestions.discard` POST `{ reason? }` "Descartar", "No es duplicado"
 * - `ai.suggestions.regenerate` / `ai.suggestions.cancel` POST
 * - `ai.requests.compare` GET `Ai/Requests/Compare` (`RequestsCompareProps`)
 * - `ai.requests.criteria.update` PUT `{ criteria: {id|null, text}[], suggestion_id }`
 */
class SuggestionController extends Controller
{
    private const VALUE_MAX = 5000;

    private const REASON_MAX = 500;

    private const CRITERIA_MAX = 20;

    private const CRITERION_MAX = 500;

    public function __construct(
        private readonly Suggestions $suggestions,
        private readonly SuggestionPresenter $presenter,
    ) {}

    public function store(Request $http, AiRequest $request): RedirectResponse
    {
        $this->suggestions->suggest($request, $this->user($http));

        return back();
    }

    public function accept(Request $http, AiSuggestion $suggestion): RedirectResponse
    {
        $data = $http->validate(
            ['value' => ['sometimes', 'nullable', 'string', 'max:'.self::VALUE_MAX]],
            ['value.max' => 'El texto puede tener hasta 5.000 caracteres.', 'value.*' => 'Escribe el texto que vas a usar.'],
        );

        $value = array_key_exists('value', $data) ? trim((string) $data['value']) : null;

        if ($value === '') {
            throw ValidationException::withMessages(['value' => 'Escribe el texto que vas a usar.']);
        }

        $this->suggestions->accept($suggestion, $this->user($http), $value);

        return back();
    }

    public function discard(Request $http, AiSuggestion $suggestion): RedirectResponse
    {
        $data = $http->validate(
            ['reason' => ['nullable', 'string', 'max:'.self::REASON_MAX]],
            ['reason.*' => 'El motivo puede tener hasta 500 caracteres.'],
        );

        $reason = trim((string) ($data['reason'] ?? ''));
        $this->suggestions->discard($suggestion, $this->user($http), $reason === '' ? null : $reason);

        return back();
    }

    public function regenerate(Request $http, AiSuggestion $suggestion): RedirectResponse
    {
        $this->suggestions->regenerate($suggestion, $this->user($http));

        return back();
    }

    public function cancel(Request $http, AiSuggestion $suggestion): RedirectResponse
    {
        $this->suggestions->cancel($suggestion, $this->user($http));

        return back();
    }

    public function compare(Request $http, AiRequest $request, AiRequest $other): Response
    {
        Gate::authorize('viewInternal', $request);
        Gate::authorize('viewInternal', $other);

        $suggestion = $this->presenter->duplicateOf($request, $other);
        abort_if($suggestion === null, 404);

        return Inertia::render('Ai/Requests/Compare', [
            'current' => $this->side($request),
            'other' => $this->side($other),
            'suggestion' => $this->presenter->one($suggestion),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function criteria(Request $http, AiRequest $request): RedirectResponse
    {
        $user = $this->user($http);
        Gate::forUser($user)->authorize('evaluate', $request);

        $data = $http->validate([
            'criteria' => ['required', 'array', 'min:1', 'max:'.self::CRITERIA_MAX],
            'criteria.*.id' => ['nullable', 'integer'],
            'criteria.*.text' => ['required', 'string', 'max:'.self::CRITERION_MAX],
            'suggestion_id' => ['nullable', 'integer'],
        ], [
            'criteria.required' => 'Escribe al menos un criterio.',
            'criteria.min' => 'Escribe al menos un criterio.',
            'criteria.max' => 'Puedes guardar hasta 20 criterios.',
            'criteria.*.text.required' => 'Escribe el criterio o quita la línea.',
            'criteria.*.text.max' => 'Cada criterio puede tener hasta 500 caracteres.',
            'criteria.*.*' => 'Revisa este criterio.',
            'suggestion_id.*' => 'La sugerencia no es válida.',
        ]);

        $lines = array_map(fn (array $line) => ['id' => isset($line['id']) ? (int) $line['id'] : null, 'text' => trim((string) $line['text'])], $data['criteria']);
        $suggestionId = isset($data['suggestion_id']) ? (int) $data['suggestion_id'] : null;

        DB::transaction(function () use ($request, $lines, $suggestionId, $user): void {
            $this->syncCriteria($request, $lines);
            $this->suggestions->creditCriteria($request, $suggestionId, array_column($lines, 'text'), $user);
        });

        return back()->with('success', 'Criterios guardados.');
    }

    /**
     * Rows by id (kept rows get their new text and position), new lines
     * created, missing rows deleted. A criterion the requester already
     * validated cannot be changed or removed.
     *
     * @param  list<array{id: int|null, text: string}>  $lines
     */
    private function syncCriteria(AiRequest $request, array $lines): void
    {
        $rows = $request->criteria()->lockForUpdate()->get()->keyBy('id');
        $ids = array_filter(array_column($lines, 'id'));

        if (array_diff($ids, $rows->keys()->all()) !== [] || count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['criteria' => 'Uno de los criterios no es de esta solicitud.']);
        }

        $texts = array_column($lines, 'text', 'id');
        $locked = $rows->filter(fn (AcceptanceCriterion $row) => $row->result !== null)
            ->first(fn (AcceptanceCriterion $row) => ($texts[$row->id] ?? null) !== $row->text);

        if ($locked !== null) {
            throw ValidationException::withMessages(['criteria' => 'No puedes cambiar ni quitar un criterio que ya validó quien la pidió.']);
        }

        $rows->except($ids)->each(fn (AcceptanceCriterion $row) => $row->delete());

        foreach (array_values($lines) as $index => $line) {
            $line['id'] === null
                ? $request->criteria()->create(['position' => $index + 1, 'text' => $line['text']])
                : $rows[$line['id']]->forceFill(['position' => $index + 1, 'text' => $line['text']])->save();
        }
    }

    /**
     * `CompareSide`: only what the requester wrote.
     *
     * @return array<string, mixed>
     */
    private function side(AiRequest $request): array
    {
        $request->loadMissing(RequestPresenter::RELATIONS);

        return [
            'request' => RequestPresenter::summary($request),
            'problem' => (string) $request->problem,
            'expected_result' => (string) $request->expected_result,
            'systems' => array_values(array_filter((array) $request->systems, 'is_string')),
        ];
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
