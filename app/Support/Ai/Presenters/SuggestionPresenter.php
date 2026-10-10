<?php

namespace App\Support\Ai\Presenters;

use App\Domain\Suggestions\SuggestionInput;
use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Models\AiSuggestion;
use App\Models\User;
use App\Support\Ai\AiSuggestionAvailability;
use App\Support\Integrations\AiProviderSettings;
use Closure;
use Illuminate\Support\Carbon;

/**
 * `Suggestion` and `RequestSuggestions` (resources/js/types/suggestions.ts).
 *
 * - Only for viewers whose `AiAvailability` is `ready` (null otherwise:
 *   hidden without `ai.manage`, and the off states show a note instead).
 * - Per kind, the newest suggestion that is neither cancelled nor
 *   superseded; duplicates newest first (discarded ones stay, collapsed).
 * - `stale` is computed: a `ready` suggestion whose input hashes differ
 *   from the subject's current ones, with what changed ("Cambió el
 *   resultado esperado") and when (the subject's `updated_at`).
 * - `payload` is null while generating or after an error.
 */
final class SuggestionPresenter
{
    public function __construct(
        private readonly SuggestionInput $input,
        private readonly AiSuggestionAvailability $ai,
        private readonly AiProviderSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forRequest(AiRequest $request, User $viewer): ?array
    {
        if ($this->ai->for($viewer) !== 'ready') {
            return null;
        }

        $rows = AiSuggestion::query()->of($request)->current()->with(['acceptedBy', 'discardedBy'])->orderByDesc('id')->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $stale = $this->staleness($this->input->requestHashes($request), SuggestionInput::REQUEST_FIELDS, $request->updated_at);
        $latest = function (string $kind) use ($rows, $stale): ?array {
            $row = $rows->firstWhere('kind', $kind);

            return $row === null ? null : $this->one($row, $stale);
        };

        return [
            'summary' => $latest('summary'),
            'summary_text' => $request->summary_text,
            'duplicates' => $rows->where('kind', 'duplicate')->map(fn (AiSuggestion $row) => $this->one($row, $stale))->values()->all(),
            'criteria' => $latest('criteria'),
            'size' => $latest('size'),
        ];
    }

    /**
     * `ReleasesShowProps.suggestion`.
     *
     * @return array<string, mixed>|null
     */
    public function releaseNotes(AiProductVersion $version, User $viewer): ?array
    {
        if ($this->ai->for($viewer) !== 'ready') {
            return null;
        }

        $row = AiSuggestion::query()->of($version)->where('kind', 'release_notes')->current()->with(['acceptedBy', 'discardedBy'])->latest('id')->first();

        return $row === null ? null : $this->one($row, $this->staleness($this->input->releaseHashes($version), SuggestionInput::RELEASE_FIELDS, $version->updated_at));
    }

    /**
     * The AI duplicate suggestion of `$request` that points to `$other`
     * (the comparison page), newest first.
     */
    public function duplicateOf(AiRequest $request, AiRequest $other): ?AiSuggestion
    {
        return AiSuggestion::query()->of($request)->where('kind', 'duplicate')->current()->with(['acceptedBy', 'discardedBy'])->orderByDesc('id')->get()
            ->first(fn (AiSuggestion $row) => ($row->payload['request']['key'] ?? null) === $other->key);
    }

    /**
     * `Suggestion`.
     *
     * @param  (Closure(AiSuggestion): ?array{reason: string, at: string})|null  $stale
     * @return array<string, mixed>
     */
    public function one(AiSuggestion $row, ?Closure $stale = null): array
    {
        $staleness = $row->state === AiSuggestion::READY && $stale !== null ? $stale($row) : null;
        $withPayload = ! in_array($row->state, [AiSuggestion::GENERATING, AiSuggestion::ERROR], true);

        return [
            'id' => $row->id,
            'kind' => $row->kind,
            'state' => $staleness === null ? $row->state : 'stale',
            'provider' => $this->providerLabel($row->provider ?? $this->settings->provider()),
            'created_at' => $row->created_at->toIso8601String(),
            'payload' => $withPayload ? $row->payload : null,
            'accepted' => $row->state === AiSuggestion::ACCEPTED && $row->accepted_at !== null
                ? ['by' => (string) $row->acceptedBy?->name, 'at' => $row->accepted_at->toIso8601String()]
                : null,
            'discarded' => $row->state === AiSuggestion::DISCARDED && $row->discarded_at !== null
                ? ['by' => (string) $row->discardedBy?->name, 'at' => $row->discarded_at->toIso8601String(), 'reason' => $row->discard_reason]
                : null,
            'stale' => $staleness,
            'error' => $row->state === AiSuggestion::ERROR ? $row->error : null,
        ];
    }

    /**
     * @param  array<string, string>  $current  current input hashes of the subject
     * @param  array<string, string>  $labels  field => Spanish label
     * @return Closure(AiSuggestion): ?array{reason: string, at: string}
     */
    private function staleness(array $current, array $labels, ?Carbon $changedAt): Closure
    {
        return function (AiSuggestion $row) use ($current, $labels, $changedAt): ?array {
            $hashes = (array) $row->input_hashes;
            $changed = array_values(array_filter(array_keys($labels), fn (string $field) => isset($hashes[$field]) && ($current[$field] ?? null) !== $hashes[$field]));

            if ($changed === []) {
                return null;
            }

            $names = array_map(fn (string $field) => $labels[$field], $changed);
            $last = array_pop($names);

            return [
                'reason' => $names === [] ? "Cambió {$last}" : 'Cambiaron '.implode(', ', $names)." y {$last}",
                'at' => ($changedAt ?? now())->toIso8601String(),
            ];
        };
    }

    private function providerLabel(string $provider): string
    {
        return (string) (AiProviderSettings::providers()[$provider]['label'] ?? $provider);
    }
}
