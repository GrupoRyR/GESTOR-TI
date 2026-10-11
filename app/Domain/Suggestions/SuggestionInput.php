<?php

namespace App\Domain\Suggestions;

use App\Domain\Ai\Brief\BriefText;
use App\Domain\Ai\SecretGuard;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Collection;

/**
 * The ONLY place that decides what an AI suggestion receives (PROMPT
 * §6.4.7, stage-d-contract "What the AI receives"). Whitelist:
 *
 * - request: title, problem, current process, expected result and the
 *   app (product name, else the systems the requester named); for
 *   duplicates, keys and titles of open requests of the same company;
 * - release: product name, tag, keys and titles of the included requests
 *   and the technical notes (GitHub body), line by line.
 *
 * Never: requester names or emails, hours, people, costs, rates, area
 * hour value, estimates, salaries, vault, attachments, comments or
 * internal notes. Free text goes through BriefText (SecretGuard, money
 * amounts, quotes of internal notes): an unsafe field is dropped whole
 * (null), an unsafe technical-notes line is dropped.
 *
 * `*Hashes()` hash the raw subject fields (sha1 per field), so a
 * suggestion turns stale when what it was made from changes.
 */
final class SuggestionInput
{
    /** Request fields sent to the AI, in order (labels for stale reasons). */
    public const REQUEST_FIELDS = [
        'title' => 'el título',
        'problem' => 'el problema',
        'current_process' => 'el proceso actual',
        'expected_result' => 'el resultado esperado',
        'app' => 'la app',
    ];

    public const RELEASE_FIELDS = [
        'requests' => 'las solicitudes incluidas',
        'technical_notes' => 'las notas técnicas',
    ];

    public function __construct(private readonly SecretGuard $secrets) {}

    /**
     * @return array{request: array<string, string|null>, open_requests?: list<array{key: string, title: string}>}
     */
    public function forRequest(AiRequest $request, bool $withOpenRequests): array
    {
        $text = $this->text($request);
        $input = ['request' => []];

        foreach ($this->rawRequest($request) as $field => $value) {
            $input['request'][$field] = $text->clean($value);
        }

        if ($withOpenRequests) {
            $input['open_requests'] = $this->openRequests($request)
                ->map(fn (AiRequest $other) => ['key' => $other->key, 'title' => $text->clean($other->title)])
                ->filter(fn (array $other) => $other['title'] !== null)
                ->values()
                ->all();
        }

        return $input;
    }

    /**
     * Words the AI would read about the request (title, problem, current
     * process, expected result), after unsafe fields are dropped.
     */
    public function requestWords(AiRequest $request): int
    {
        $fields = $this->forRequest($request, false)['request'];
        unset($fields['app']);

        return str_word_count(implode(' ', array_filter($fields)), 0, 'áéíóúüñÁÉÍÓÚÜÑ0123456789');
    }

    /**
     * Open requests of the same company a duplicate may point to (newest first).
     *
     * @return Collection<int, AiRequest>
     */
    public function openRequests(AiRequest $request): Collection
    {
        return AiRequest::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $request->company_id)
            ->whereKeyNot($request->id)
            ->whereNotIn('status', RequestStatus::finishedValues())
            ->whereNull('merged_into_id')
            ->latest('id')
            ->limit((int) config('gestor.ai.suggestions.open_requests', 60))
            ->get(['id', 'key', 'title', 'status', 'people_count']);
    }

    /**
     * @return array<string, string>
     */
    public function requestHashes(AiRequest $request): array
    {
        return array_map(fn (?string $value) => sha1((string) $value), $this->rawRequest($request));
    }

    /**
     * @return array{product: string, tag: string, requests: list<array{key: string, title: string}>, technical_notes: string}
     */
    public function forRelease(AiProductVersion $version): array
    {
        $text = new BriefText($this->secrets);
        $lines = preg_split('/\R/', (string) $version->technical_notes) ?: [];

        return [
            'product' => (string) $version->product?->name,
            'tag' => (string) ($version->tag ?? $version->version),
            'requests' => collect($this->releaseRequests($version))
                ->map(fn (array $request) => ['key' => $request['key'], 'title' => $text->clean($request['title'])])
                ->filter(fn (array $request) => $request['title'] !== null)
                ->values()
                ->all(),
            'technical_notes' => trim(implode("\n", array_filter($lines, fn (string $line) => trim($line) === '' || $text->safe($line)))),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function releaseHashes(AiProductVersion $version): array
    {
        return [
            'requests' => sha1((string) json_encode($this->releaseRequests($version))),
            'technical_notes' => sha1((string) $version->technical_notes),
        ];
    }

    /**
     * @return list<array{key: string, title: string}>
     */
    private function releaseRequests(AiProductVersion $version): array
    {
        return $version->requests()->orderBy('ai_requests.id')->get(['ai_requests.id', 'key', 'title'])
            ->map(fn (AiRequest $request) => ['key' => $request->key, 'title' => $request->title])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string|null>
     */
    private function rawRequest(AiRequest $request): array
    {
        $systems = array_filter(array_map(fn ($system) => is_scalar($system) ? trim((string) $system) : '', (array) $request->systems));

        return [
            'title' => $request->title,
            'problem' => $request->problem,
            'current_process' => $request->current_process,
            'expected_result' => $request->expected_result,
            'app' => $request->product?->name ?? ($systems === [] ? null : implode(', ', $systems)),
        ];
    }

    private function text(AiRequest $request): BriefText
    {
        $notes = $request->comments()
            ->where('visibility', CommentVisibility::Internal->value)
            ->pluck('body');

        return new BriefText($this->secrets, $notes);
    }
}
