<?php

namespace App\Domain\Suggestions;

use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Models\AiSuggestion;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Services\Ai\AiRun;
use App\Services\Ai\AiRuntime;
use App\Support\Integrations\AiProviderSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Throwable;

/**
 * Fills `generating` suggestions (App\Jobs\GenerateSuggestions, database
 * queue). The rows of one batch share ONE provider call through
 * App\Services\Ai\AiRuntime::run() (ai_interactions, monthly cap, never
 * throws):
 *
 * - request batch ("Sugerir con IA"): summary + criteria + size +
 *   duplicates in one call (`suggestion.request`); a regenerate asks one
 *   kind (`suggestion.summary|criteria|size|duplicates`);
 * - release: `suggestion.release_notes`.
 *
 * Race-safe: a row is only written while it is still `generating`
 * (conditional update), so a cancel that arrives first always wins; a
 * batch whose rows were all cancelled is not sent. Each finished kind
 * supersedes the older suggestions of its kind (duplicates keep the
 * discarded ones, which never come back for the same request).
 */
final class SuggestionGenerator
{
    public function __construct(
        private readonly AiRuntime $runtime,
        private readonly SuggestionInput $input,
        private readonly AiProviderSettings $settings,
    ) {}

    /**
     * @param  list<int>  $ids
     * @param  bool  $withDuplicates  a request batch also looks for duplicates (they get rows once found)
     */
    public function generate(array $ids, bool $withDuplicates = false): void
    {
        $rows = AiSuggestion::query()->whereIn('id', $ids)->where('state', AiSuggestion::GENERATING)->orderBy('id')->get();
        $first = $rows->first();

        if ($first === null) {
            return;
        }

        $subject = $this->subject($first);

        if ($subject === null) {
            $this->fail($rows, 'subject_missing', 'Lo que se iba a revisar ya no existe.');

            return;
        }

        if (! $this->runtime->ready()) {
            $this->fail($rows, 'ai_off', 'La IA está apagada: no hay llave o se alcanzó el tope del mes.');

            return;
        }

        $subject instanceof AiRequest ? $this->forRequest($subject, $rows, $withDuplicates) : $this->forRelease($subject, $rows);
    }

    /**
     * @param  Collection<int, AiSuggestion>  $rows
     */
    private function forRequest(AiRequest $request, Collection $rows, bool $withDuplicates): void
    {
        $kinds = $rows->pluck('kind')->unique()->values()->all();
        $withDuplicates = $withDuplicates || in_array('duplicate', $kinds, true);
        $asked = $withDuplicates ? array_values(array_unique([...$kinds, 'duplicate'])) : $kinds;
        $input = $this->input->forRequest($request, $withDuplicates);
        $hashes = $this->input->requestHashes($request);

        $run = $this->runtime->run(new RequestSuggestionAgent($asked), $this->json($input), $this->requester($rows), RequestSuggestionAgent::feature($asked));

        if ($run->failed()) {
            $this->failRun($rows, $run, 'La solicitud no cambió.');

            return;
        }

        $data = SuggestionAnswer::decode($run->text());

        foreach ($rows->where('kind', '!=', 'duplicate') as $row) {
            $payload = $data === null ? null : match ($row->kind) {
                'summary' => SuggestionAnswer::summary($data['summary'] ?? null),
                'criteria' => SuggestionAnswer::criteria($data['criteria'] ?? null),
                'size' => SuggestionAnswer::size($data['size'] ?? null),
                default => null,
            };

            $payload === null
                ? $this->failOne($row, 'bad_answer', 'La respuesta de la IA no se pudo leer. La solicitud no cambió.')
                : $this->fill($row, $payload, $run, $hashes);
        }

        if ($withDuplicates) {
            $offered = array_column($input['open_requests'] ?? [], 'key');
            $this->duplicates($request, $rows, $data === null ? null : SuggestionAnswer::duplicates($data['duplicates'] ?? null, $offered), $run, $hashes);
        }
    }

    /**
     * @param  Collection<int, AiSuggestion>  $rows
     */
    private function forRelease(AiProductVersion $version, Collection $rows): void
    {
        $input = $this->input->forRelease($version);
        $hashes = $this->input->releaseHashes($version);

        $run = $this->runtime->run(new ReleaseNotesAgent, $this->json($input), $this->requester($rows), ReleaseNotesAgent::FEATURE);

        if ($run->failed()) {
            $this->failRun($rows, $run, 'La versión no cambió.');

            return;
        }

        $data = SuggestionAnswer::decode($run->text());
        $notes = $data === null ? null : SuggestionAnswer::releaseNotes($data);
        $keys = array_column($input['requests'], 'key');

        foreach ($rows as $row) {
            $notes === null
                ? $this->failOne($row, 'bad_answer', 'La respuesta de la IA no se pudo leer. La versión no cambió.')
                : $this->fill($row, [...$notes, 'source_note' => self::sourceNote($keys)], $run, $hashes);
        }
    }

    /**
     * "Hecha con el título de GIA-118 y las notas técnicas del release. No usa costos ni notas internas."
     *
     * @param  list<string>  $keys
     */
    public static function sourceNote(array $keys): string
    {
        $last = array_pop($keys);
        $list = $keys === [] ? (string) $last : implode(', ', $keys).' y '.$last;
        $what = $last === null ? 'Hecha con las notas técnicas del release' : 'Hecha con '.($keys === [] ? 'el título' : 'los títulos')." de {$list} y las notas técnicas del release";

        return $what.'. No usa costos ni notas internas.';
    }

    /**
     * Duplicates: a placeholder row (regenerate) takes the first one found;
     * the others become new rows of the batch. Requests already discarded
     * on this request never come back; older duplicates are superseded.
     *
     * @param  Collection<int, AiSuggestion>  $rows
     * @param  array<string, array<string, mixed>>|null  $found  null = unreadable answer
     * @param  array<string, string>  $hashes
     */
    private function duplicates(AiRequest $request, Collection $rows, ?array $found, AiRun $run, array $hashes): void
    {
        $placeholder = $rows->firstWhere('kind', 'duplicate');
        $batch = (string) $rows->first()?->batch;

        if ($found === null) {
            if ($placeholder !== null) {
                $this->failOne($placeholder, 'bad_answer', 'La respuesta de la IA no se pudo leer. La solicitud no cambió.');
            }

            return;
        }

        // Every row of the batch was cancelled meanwhile: nothing is added.
        if (! AiSuggestion::query()->whereIn('id', $rows->modelKeys())->where('state', '!=', AiSuggestion::CANCELLED)->exists()) {
            return;
        }

        $discarded = AiSuggestion::query()->of($request)->where('kind', 'duplicate')->where('state', AiSuggestion::DISCARDED)->get()
            ->map(fn (AiSuggestion $row) => $row->payload['request']['key'] ?? null)->filter()->all();
        $others = $this->input->openRequests($request)->keyBy('key');
        $written = [];

        foreach ($found as $key => $item) {
            $other = $others->get($key);

            if ($other === null || in_array($key, $discarded, true)) {
                continue;
            }

            $payload = [
                'request' => ['key' => $other->key, 'title' => $other->title, 'status' => $other->status->value, 'people' => (int) ($other->people_count ?? 0)],
                ...$item,
            ];

            if ($placeholder !== null && $written === []) {
                if ($this->fill($placeholder, $payload, $run, $hashes, false)) {
                    $written[] = $placeholder->id;
                }

                continue;
            }

            $written[] = AiSuggestion::query()->create([
                'subject_type' => AiRequest::class,
                'subject_id' => $request->id,
                'kind' => 'duplicate',
                'state' => AiSuggestion::READY,
                'batch' => $batch,
                'requested_by_id' => $rows->first()?->requested_by_id,
                ...$this->runColumns($run, $payload, $hashes),
            ])->id;
        }

        if ($placeholder !== null && $written === []) {
            AiSuggestion::query()->whereKey($placeholder->id)->where('state', AiSuggestion::GENERATING)->delete();
        }

        AiSuggestion::query()->of($request)->where('kind', 'duplicate')->whereNotIn('id', [...$written, ...$rows->modelKeys()])
            ->whereNotIn('state', [AiSuggestion::DISCARDED, AiSuggestion::GENERATING])->whereNull('superseded_at')
            ->update(['superseded_at' => now()]);
    }

    /**
     * Writes a finished suggestion only while it is still generating.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $hashes
     */
    private function fill(AiSuggestion $row, array $payload, AiRun $run, array $hashes, bool $supersede = true): bool
    {
        $written = AiSuggestion::query()->whereKey($row->id)->where('state', AiSuggestion::GENERATING)
            ->update(['state' => AiSuggestion::READY, ...$this->runColumns($run, $payload, $hashes, true)]) === 1;

        if ($written && $supersede) {
            AiSuggestion::query()->where('subject_type', $row->subject_type)->where('subject_id', $row->subject_id)
                ->where('kind', $row->kind)->where('id', '<', $row->id)->whereNull('superseded_at')
                ->where('state', '!=', AiSuggestion::GENERATING)
                ->update(['superseded_at' => now()]);
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $hashes
     * @return array<string, mixed>
     */
    private function runColumns(AiRun $run, array $payload, array $hashes, bool $encoded = false): array
    {
        $interaction = $run->lastInteraction();

        return [
            'provider' => $interaction?->provider ?? $this->settings->provider(),
            'model' => $interaction?->model ?? $this->settings->model(),
            'payload' => $encoded ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload,
            'input_hashes' => $encoded ? json_encode($hashes) : $hashes,
            'ai_interaction_id' => $interaction?->id,
            'error' => null,
        ];
    }

    /**
     * @param  Collection<int, AiSuggestion>  $rows
     */
    private function failRun(Collection $rows, AiRun $run, string $unchanged): void
    {
        $timeout = false;

        for ($error = $run->error; $error !== null; $error = $error->getPrevious()) {
            $timeout = $timeout || self::isTimeout($error);
        }

        $provider = (string) (AiProviderSettings::providers()[$this->settings->provider()]['label'] ?? 'La IA');
        $interaction = $run->lastInteraction();

        foreach ($rows as $row) {
            $this->failOne(
                $row,
                $timeout ? 'ai_timeout' : 'ai_error',
                ($timeout ? "{$provider} no respondió a tiempo. " : "{$provider} devolvió un error. ").$unchanged,
                $interaction?->id,
            );
        }
    }

    /**
     * @param  Collection<int, AiSuggestion>  $rows
     */
    private function fail(Collection $rows, string $code, string $detail): void
    {
        foreach ($rows as $row) {
            $this->failOne($row, $code, $detail);
        }
    }

    private function failOne(AiSuggestion $row, string $code, string $detail, ?int $interactionId = null): void
    {
        AiSuggestion::query()->whereKey($row->id)->where('state', AiSuggestion::GENERATING)->update([
            'state' => AiSuggestion::ERROR,
            'payload' => null,
            'ai_interaction_id' => $interactionId,
            'error' => json_encode(self::error($code, $detail), JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * `SuggestionError`.
     *
     * @return array{message: string, detail: string, code: string, at: string}
     */
    public static function error(string $code, string $detail, string $message = 'No se pudo generar la sugerencia.'): array
    {
        return ['message' => $message, 'detail' => $detail, 'code' => $code, 'at' => now()->toIso8601String()];
    }

    private function subject(AiSuggestion $row): AiRequest|AiProductVersion|null
    {
        return match ($row->subject_type) {
            AiRequest::class => AiRequest::query()->withoutGlobalScope(CompanyScope::class)->with('product')->find($row->subject_id),
            AiProductVersion::class => AiProductVersion::query()->with('product')->find($row->subject_id),
            default => null,
        };
    }

    /**
     * @param  Collection<int, AiSuggestion>  $rows
     */
    private function requester(Collection $rows): ?User
    {
        $id = $rows->first()?->requested_by_id;

        return $id === null ? null : User::query()->find($id);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function json(array $input): string
    {
        return (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private static function isTimeout(Throwable $error): bool
    {
        return $error instanceof ConnectionException || $error instanceof ProviderConnectionException;
    }
}
