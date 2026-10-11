<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Import\ImportFields;
use App\Models\Area;
use App\Models\User;
use App\Services\Ai\AiRuntime;
use Illuminate\Support\Facades\Log;

/**
 * `/aprender` parser with the AI provider (C3), bound instead of the C2
 * heuristic while the provider is ready (key set, under the cap; see
 * AppServiceProvider). The heuristic result is the base; the model's JSON
 * overrides type, title, companies, area and the fields of the type it
 * read. Anything unusable (provider failure, not JSON, unknown type,
 * unknown company) keeps the heuristic value. Visibility stays "Solo TI"
 * and the owner empty until someone approves the draft.
 */
final class AiDraftParser implements DraftParser
{
    public const FEATURE = 'assistant_learn';

    private const TITLE_LENGTH = 160;

    private const FIELD_LENGTH = 5000;

    public function __construct(
        private readonly AiRuntime $runtime,
        private readonly HeuristicDraftParser $heuristic,
        private readonly CompanyNames $companies,
    ) {}

    public function parse(string $text, User $author): array
    {
        $base = $this->heuristic->parse($text, $author);

        if (! $this->runtime->ready()) {
            return $base;
        }

        $run = $this->runtime->run(new LearnAgent, $text, $author, self::FEATURE);

        if ($run->failed()) {
            Log::warning('Learn draft parser fell back to the heuristic', ['error' => $run->error === null ? 'empty response' : AiRuntime::describe($run->error)]);

            return $base;
        }

        $data = self::decode($run->text());

        return $data === null ? $base : $this->merge($base, $data);
    }

    /**
     * The JSON object of the answer (fences and text around it are ignored).
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $data = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($data) && (isset($data['type']) || isset($data['title'])) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function merge(array $base, array $data): array
    {
        $proposed = $base;
        $origins = (array) $base['origins'];

        $type = is_string($data['type'] ?? null) && in_array($data['type'], KnowledgeTypes::TYPES, true) ? $data['type'] : $base['type'];
        if ($type !== $base['type']) {
            $proposed['fields'] = [];
            $origins = array_intersect_key($origins, array_flip(['company', 'area', 'owner']));
        }
        $proposed['type'] = $type;

        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        if ($title !== '') {
            $proposed['title'] = mb_substr($title, 0, self::TITLE_LENGTH);
        }

        $names = array_filter((array) ($data['companies'] ?? []), 'is_string');
        $companyIds = $names === [] ? [] : $this->companies->mentioned(implode(', ', $names));
        if ($companyIds !== []) {
            $proposed['company_ids'] = $companyIds;
            $origins['company'] = 'message';
        }

        $areaId = $this->area(is_string($data['area'] ?? null) ? $data['area'] : null, (array) $proposed['company_ids']);
        if ($areaId !== null) {
            $proposed['area_id'] = $areaId;
            $origins['area'] = 'message';
        } elseif ($proposed['area_id'] !== null && ! $this->areaBelongs((int) $proposed['area_id'], (array) $proposed['company_ids'])) {
            $proposed['area_id'] = null;
            unset($origins['area']);
        }

        $allowed = KnowledgeTypes::fields($type);
        foreach ((array) ($data['fields'] ?? []) as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $allowed)) {
                continue;
            }

            $clean = $key === KnowledgeTypes::LIST_FIELD
                ? array_values(array_filter(array_map(fn ($step) => is_scalar($step) ? mb_substr(trim((string) $step), 0, self::FIELD_LENGTH) : '', (array) $value), fn (string $step) => $step !== ''))
                : (is_scalar($value) ? mb_substr(trim((string) $value), 0, self::FIELD_LENGTH) : '');

            if ($clean !== '' && $clean !== []) {
                $proposed['fields'][$key] = $clean;
                $origins[$key] = 'message';
            }
        }

        $proposed['owner_id'] = null;
        $proposed['visibility'] = 'it';
        $proposed['origins'] = $origins;

        return $proposed;
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function area(?string $name, array $companyIds): ?int
    {
        if ($name === null || trim($name) === '' || $companyIds === []) {
            return null;
        }

        $wanted = ImportFields::normalize($name);

        return Area::withoutGlobalScopes()->where('is_active', true)->whereIn('company_id', $companyIds)->orderBy('id')->get()
            ->first(fn (Area $area) => ImportFields::normalize($area->name) === $wanted)?->id;
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function areaBelongs(int $areaId, array $companyIds): bool
    {
        return Area::withoutGlobalScopes()->whereKey($areaId)->whereIn('company_id', $companyIds)->exists();
    }
}
