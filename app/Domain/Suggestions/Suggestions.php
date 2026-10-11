<?php

namespace App\Domain\Suggestions;

use App\Enums\Ai\EffortSize;
use App\Jobs\GenerateSuggestions;
use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Models\AiSuggestion;
use App\Models\User;
use App\Services\Ai\AiRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What people do with AI suggestions (Stage D6, `ai.manage`). Nothing here
 * changes a request or a release by itself, except what the person
 * explicitly confirms:
 *
 * - `suggest()` ("Sugerir con IA"): one batch for the kinds the request
 *   does not have yet (summary, criteria, size; duplicates are looked for
 *   in the same call), queued (App\Jobs\GenerateSuggestions);
 * - `regenerate()` ("Volver a sugerir" / "Volver a intentar"): a new row
 *   of the same kind, one call; the old row is superseded once it is done;
 * - `cancel()`: only while generating; the job never overwrites it;
 * - `accept()` ("Usar"): stores the used value; a summary also becomes the
 *   request's "Resumen para TI" (`summary_text`), the only write to the
 *   request. Criteria and size are only credited by Jhon's own forms
 *   (`creditCriteria()`, `creditSize()`), release notes by
 *   App\Actions\Ai\ReleaseNotes (`releaseNotesSuggestion()` + `markAccepted()`);
 * - `discard()`: hides it on this request and logs it; it never touches
 *   another request nor trains anything.
 *
 * Access goes through the subject: a request in the viewer's company scope
 * (AiRequestPolicy::evaluate) or the release's product (manageVersions).
 */
final class Suggestions
{
    /** Request kinds "Sugerir con IA" creates rows for (duplicates get rows when found). */
    private const BATCH_KINDS = ['summary', 'criteria', 'size'];

    public function __construct(
        private readonly AiRuntime $runtime,
        private readonly SuggestionInput $input,
    ) {}

    public function suggest(AiRequest $request, User $user): void
    {
        Gate::forUser($user)->authorize('evaluate', $request);
        $this->assertReady();

        $existing = AiSuggestion::query()->of($request)->current()->pluck('kind')->all();
        $kinds = array_values(array_diff(self::BATCH_KINDS, $existing));

        if ($kinds === []) {
            throw ValidationException::withMessages(['suggestion' => 'Ya hay sugerencias para esta solicitud. Usa «Volver a sugerir» en la que quieras cambiar.']);
        }

        if ($this->tooShort($request)) {
            if (in_array('summary', $kinds, true)) {
                $this->tooShortRow($request, 'summary', $user);
            }

            return;
        }

        $this->queue($request, $kinds, $user, true);
    }

    public function regenerate(AiSuggestion $suggestion, User $user): void
    {
        $subject = $this->subject($suggestion, $user);

        if ($suggestion->isGenerating()) {
            throw ValidationException::withMessages(['suggestion' => 'Esta sugerencia ya se está generando.']);
        }

        $this->assertReady();

        if ($subject instanceof AiRequest && $this->tooShort($subject)) {
            $this->tooShortRow($subject, $suggestion->kind, $user);

            return;
        }

        $this->queue($subject, [$suggestion->kind], $user, false);
    }

    public function cancel(AiSuggestion $suggestion, User $user): void
    {
        $this->subject($suggestion, $user);

        $cancelled = AiSuggestion::query()->whereKey($suggestion->id)->where('state', AiSuggestion::GENERATING)
            ->update(['state' => AiSuggestion::CANCELLED, 'updated_at' => now()]);

        if ($cancelled !== 1) {
            throw ValidationException::withMessages(['suggestion' => 'Esta sugerencia ya no se está generando.']);
        }
    }

    public function accept(AiSuggestion $suggestion, User $user, ?string $value): void
    {
        $subject = $this->subject($suggestion, $user);
        $this->assertUsable($suggestion);

        DB::transaction(function () use ($suggestion, $subject, $user, $value): void {
            $payload = (array) $suggestion->payload;
            $used = match ($suggestion->kind) {
                'summary' => ['text' => $value ?? (string) ($payload['text'] ?? '')],
                'release_notes' => ['body' => $value ?? (string) ($payload['body'] ?? '')],
                'criteria' => ['items' => $value !== null ? [$value] : array_column((array) ($payload['items'] ?? []), 'text')],
                'size' => ['size' => (string) ($payload['size'] ?? '')],
                default => ['key' => (string) ($payload['request']['key'] ?? '')],
            };

            $this->markAccepted($suggestion, $used, $user);

            if ($suggestion->kind === 'summary' && $subject instanceof AiRequest) {
                $subject->forceFill(['summary_text' => $used['text']])->save();
            }
        });
    }

    public function discard(AiSuggestion $suggestion, User $user, ?string $reason): void
    {
        $this->subject($suggestion, $user);

        if (in_array($suggestion->state, [AiSuggestion::GENERATING, AiSuggestion::CANCELLED], true)) {
            throw ValidationException::withMessages(['suggestion' => 'Esta sugerencia todavía no está lista.']);
        }

        $suggestion->forceFill([
            'state' => AiSuggestion::DISCARDED,
            'discarded_by_id' => $user->id,
            'discarded_at' => now(),
            'discard_reason' => $reason,
        ])->save();

        $this->log($suggestion, $user, 'ai_suggestion_discarded', 'Sugerencia de IA descartada', ['with_reason' => $reason !== null]);
    }

    /**
     * "Guardar criterios": the criteria suggestion Jhon kept items from.
     *
     * @param  list<string>  $texts  saved criteria
     */
    public function creditCriteria(AiRequest $request, ?int $suggestionId, array $texts, User $user): void
    {
        if ($suggestionId === null) {
            return;
        }

        $suggestion = AiSuggestion::query()->of($request)->where('kind', 'criteria')->whereKey($suggestionId)
            ->whereNotIn('state', [AiSuggestion::GENERATING, AiSuggestion::CANCELLED, AiSuggestion::ERROR])->first();

        if ($suggestion === null) {
            throw ValidationException::withMessages(['suggestion_id' => 'La sugerencia no es de los criterios de esta solicitud.']);
        }

        $this->markAccepted($suggestion, ['items' => $texts], $user);
    }

    /**
     * "Guardar evaluación": credits the size suggestion only when the saved
     * size is the one it proposed ("Usar M"); anything else is ignored.
     */
    public function creditSize(AiRequest $request, ?int $suggestionId, ?EffortSize $size, User $user): void
    {
        if ($suggestionId === null || $size === null) {
            return;
        }

        $suggestion = AiSuggestion::query()->of($request)->where('kind', 'size')->whereKey($suggestionId)
            ->whereIn('state', [AiSuggestion::READY, AiSuggestion::ACCEPTED])->first();

        if ($suggestion !== null && ($suggestion->payload['size'] ?? null) === $size->value) {
            $this->markAccepted($suggestion, ['size' => $size->value], $user);
        }
    }

    /**
     * The `release_notes` suggestion `suggestion_id` names (null when typed
     * by hand); refused when it belongs to something else.
     */
    public function releaseNotesSuggestion(AiProductVersion $version, ?int $suggestionId): ?AiSuggestion
    {
        if ($suggestionId === null) {
            return null;
        }

        return AiSuggestion::query()->of($version)->where('kind', 'release_notes')->whereKey($suggestionId)
            ->whereNotIn('state', [AiSuggestion::GENERATING, AiSuggestion::CANCELLED, AiSuggestion::ERROR])->first()
            ?? throw ValidationException::withMessages(['suggestion_id' => 'La sugerencia no es de esta versión.']);
    }

    /**
     * Records who used a suggestion and the value they kept.
     *
     * @param  array<string, mixed>  $value
     */
    public function markAccepted(AiSuggestion $suggestion, array $value, User $user): void
    {
        $suggestion->forceFill([
            'state' => AiSuggestion::ACCEPTED,
            'accepted_value' => $value,
            'accepted_by_id' => $user->id,
            'accepted_at' => now(),
        ])->save();

        $this->log($suggestion, $user, 'ai_suggestion_accepted', 'Sugerencia de IA usada');
    }

    /**
     * A GitHub release arrived: queue a draft of its user notes once (no
     * person asked; nothing is sent to requesters until IT confirms).
     */
    public function draftReleaseNotes(AiProductVersion $version): void
    {
        if ($version->notified_at !== null || ! $this->runtime->ready()) {
            return;
        }

        if (AiSuggestion::query()->of($version)->where('kind', 'release_notes')->current()->exists()) {
            return;
        }

        $this->queue($version, ['release_notes'], null, false);
    }

    /**
     * The subject the viewer may act on (404 outside their company scope).
     */
    public function subject(AiSuggestion $suggestion, User $user): AiRequest|AiProductVersion
    {
        if ($suggestion->subject_type === AiRequest::class) {
            $request = AiRequest::query()->find($suggestion->subject_id);
            abort_if($request === null, 404);
            Gate::forUser($user)->authorize('evaluate', $request);

            return $request;
        }

        $version = $suggestion->subject_type === AiProductVersion::class
            ? AiProductVersion::query()->with('product')->find($suggestion->subject_id)
            : null;
        abort_if($version?->product === null, 404);
        Gate::forUser($user)->authorize('view', $version->product);
        Gate::forUser($user)->authorize('manageVersions', $version->product);

        return $version;
    }

    /**
     * @param  list<string>  $kinds
     */
    private function queue(AiRequest|AiProductVersion $subject, array $kinds, ?User $user, bool $withDuplicates): void
    {
        $batch = (string) Str::uuid();

        $ids = DB::transaction(fn (): array => array_map(fn (string $kind) => AiSuggestion::query()->create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'kind' => $kind,
            'state' => AiSuggestion::GENERATING,
            'batch' => $batch,
            'requested_by_id' => $user?->id,
        ])->id, $kinds));

        GenerateSuggestions::dispatch($ids, $withDuplicates)->afterCommit();
    }

    private function tooShort(AiRequest $request): bool
    {
        return $this->input->requestWords($request) < (int) config('gestor.ai.suggestions.min_words', 15);
    }

    private function tooShortRow(AiRequest $request, string $kind, User $user): void
    {
        $words = (int) config('gestor.ai.suggestions.min_words', 15);

        AiSuggestion::query()->create([
            'subject_type' => AiRequest::class,
            'subject_id' => $request->id,
            'kind' => $kind,
            'state' => AiSuggestion::ERROR,
            'requested_by_id' => $user->id,
            'error' => SuggestionGenerator::error(
                'too_short',
                "{$request->key} tiene menos de {$words} palabras. Pídele más detalle a quien la envió.",
                $kind === 'summary' ? 'No hay suficiente texto para resumir.' : 'No hay suficiente texto para sugerir.',
            ),
        ]);
    }

    private function assertReady(): void
    {
        if (! $this->runtime->ready()) {
            throw ValidationException::withMessages(['suggestion' => 'La IA no está disponible: no hay llave o se alcanzó el tope del mes.']);
        }
    }

    private function assertUsable(AiSuggestion $suggestion): void
    {
        if (! $suggestion->isUsable()) {
            throw ValidationException::withMessages(['suggestion' => 'Esta sugerencia ya no se puede usar.']);
        }
    }

    /**
     * Audit in the `ai` log: ids and kind only, never the suggested text.
     *
     * @param  array<string, mixed>  $extra
     */
    private function log(AiSuggestion $suggestion, User $user, string $event, string $description, array $extra = []): void
    {
        activity('ai')
            ->performedOn($suggestion)
            ->causedBy($user)
            ->event($event)
            ->withProperties(['suggestion_id' => $suggestion->id, 'kind' => $suggestion->kind, 'subject_type' => class_basename($suggestion->subject_type), 'subject_id' => $suggestion->subject_id, ...$extra])
            ->log("{$description} ({$suggestion->kind})");
    }
}
