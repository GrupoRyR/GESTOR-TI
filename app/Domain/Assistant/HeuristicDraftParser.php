<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Import\ImportFields;
use App\Models\Area;
use App\Models\User;

/**
 * `/aprender` parser without AI (C2): a first guess the approver always
 * reviews. It never invents facts; whatever it cannot read stays empty
 * ("Falta").
 *
 * - Type by keywords (informe/reporte, sincroniza/flujo, pasos/procedimiento,
 *   significa/quiere decir), else Sistema.
 * - Title: the first sentence (up to 80 characters); description: the
 *   whole text.
 * - Companies named in the text, else the author's company (assumed);
 *   area named in the text, else the author's area (assumed).
 * - Visibility "Solo TI" until someone approves it for everybody.
 * - Reports: "lo envía X", "a las 6 a. m." and "… a gerencia" fill the
 *   source system, schedule and recipients; systems: "Qué es" is the text;
 *   glossary: "X es/significa Y".
 */
final class HeuristicDraftParser implements DraftParser
{
    private const TITLE_LENGTH = 80;

    private const TIME = '\d{1,2}(?::\d{2})?(?:\s*[ap]\.?\s?m\.?)?';

    public function __construct(private readonly CompanyNames $companies) {}

    public function parse(string $text, User $author): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $type = $this->type($text);
        $origins = [];

        $companyIds = $this->companies->mentioned($text);
        if ($companyIds !== []) {
            $origins['company'] = 'message';
        } elseif ($author->company_id !== null && $this->companies->active()->has((int) $author->company_id)) {
            $companyIds = [(int) $author->company_id];
            $origins['company'] = 'assumed';
        }

        $areaId = $this->area($text, $companyIds, $author, $origins);
        $fields = $this->fields($type, $text);
        foreach (array_keys($fields) as $key) {
            $origins[$key] = 'message';
        }

        return [
            'type' => $type,
            'title' => $this->title($text),
            'description' => self::sentence($text),
            'company_ids' => $companyIds,
            'area_id' => $areaId,
            'owner_id' => null,
            'visibility' => 'it',
            'fields' => $fields,
            'origins' => $origins,
        ];
    }

    private function type(string $text): string
    {
        $normalized = ImportFields::normalize($text);

        return match (true) {
            preg_match('/\b(significa|quiere decir|es la sigla|se le dice|tambien se dice)\b/', $normalized) === 1 => 'glossary',
            preg_match('/\b(informe|informes|reporte|reportes)\b/', $normalized) === 1 => 'report',
            preg_match('/\b(sincroniza|sincronizan|flujo|integracion|se pasa de|viaja de)\b/', $normalized) === 1 => 'data_flow',
            preg_match('/\b(pasos|procedimiento)\b|^como |\bpara (crear|pedir|solicitar|conectarse|instalar|configurar)\b/', $normalized) === 1 => 'procedure',
            default => 'system',
        };
    }

    private function title(string $text): string
    {
        $first = preg_split('/(?<=[.!?])\s+(?=[¿¡A-ZÁÉÍÓÚÑ])|[;\n]/u', $text)[0] ?? $text;
        $first = rtrim(trim($first), '.');

        if (mb_strlen($first) > self::TITLE_LENGTH) {
            $cut = mb_substr($first, 0, self::TITLE_LENGTH);
            $space = mb_strrpos($cut, ' ');
            $first = rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, ' ,');
        }

        return self::upperFirst($first);
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, string>  $origins
     */
    private function area(string $text, array $companyIds, User $author, array &$origins): ?int
    {
        if ($companyIds === []) {
            return null;
        }

        $normalized = ' '.ImportFields::normalize($text).' ';
        $areas = Area::withoutGlobalScopes()->where('is_active', true)->whereIn('company_id', $companyIds)->get()
            ->sortByDesc(fn (Area $area) => mb_strlen($area->name));

        foreach ($companyIds as $companyId) {
            foreach ($areas->where('company_id', $companyId) as $area) {
                if (str_contains($normalized, ' '.ImportFields::normalize($area->name).' ')) {
                    $origins['area'] = 'message';

                    return $area->id;
                }
            }
        }

        $own = $author->employee?->area_id;
        if ($own !== null && $areas->firstWhere('id', $own) !== null) {
            $origins['area'] = 'assumed';

            return (int) $own;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function fields(string $type, string $text): array
    {
        $fields = [];

        if ($type === 'report') {
            if (preg_match('/\b(?:lo|la|los|las) (?:env[ií]a|envían|manda|genera|saca) (.+?)(?= a las? | cada | todos | por |,|\.|$)/u', $text, $match) === 1) {
                $fields['source_system'] = trim($match[1]);
            }
            if (preg_match('/\ba las? ('.self::TIME.')(.*)$/u', $text, $match) === 1) {
                $fields['schedule'] = trim($match[1]);
                if (preg_match('/^\s+(?:a|para) (.+)$/u', $match[2], $rest) === 1) {
                    $fields['recipients'] = rtrim(trim($rest[1]), '.');
                }
            }
        }

        if ($type === 'system') {
            $fields['what_is'] = self::sentence($text);
        }

        if ($type === 'glossary' && preg_match('/^(.+?)\s+(?:es|significa|quiere decir)\s+(.+)$/iu', $text, $match) === 1) {
            $fields['term'] = self::upperFirst(trim($match[1]));
            $fields['definition'] = self::sentence($match[2]);
        }

        return $fields;
    }

    private static function sentence(string $text): string
    {
        $text = self::upperFirst(trim($text));

        return preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }

    private static function upperFirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
