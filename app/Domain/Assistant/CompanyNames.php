<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Import\ImportFields;
use App\Models\Company;
use Illuminate\Support\Collection;

/**
 * Companies as people write them ("Segurex", "Derivados", "Segurex y
 * Derivados", "Todas"), for the Excel import and the `/aprender` drafts.
 * Driven by the `companies` table (never assumes two companies): a company
 * answers to its name, short name, slug and the distinctive words of its
 * names ("Derivados" for "Soluciones y Derivados de Colombia").
 */
final class CompanyNames
{
    /** Words too generic to name a company on their own. */
    private const GENERIC = ['grupo', 'empresa', 'empresas', 'soluciones', 'servicios', 'latam', 'colombia', 'sociedad', 'comercial'];

    /** Values that mean every company. */
    private const ALL = ['todas', 'ambas', 'todas las empresas', 'las dos', 'grupo ryr'];

    /** @var Collection<int, Company>|null */
    private ?Collection $companies = null;

    /**
     * Active companies, by id.
     *
     * @return Collection<int, Company>
     */
    public function active(): Collection
    {
        return $this->companies ??= Company::query()->active()->orderBy('id')->get()->keyBy('id');
    }

    /**
     * A cell like "Segurex y Derivados": the ids, or the first part that
     * names no company. "Todas" = every id of `$all`.
     *
     * @param  list<int>  $all
     * @return array{ids: list<int>, unknown: ?string}
     */
    public function parse(string $value, array $all): array
    {
        $value = trim($value);
        if (in_array(ImportFields::normalize($value), self::ALL, true)) {
            return ['ids' => $all, 'unknown' => null];
        }

        $whole = $this->find($value);
        if ($whole !== null) {
            return ['ids' => [$whole], 'unknown' => null];
        }

        $ids = [];
        foreach (preg_split('/\s*[,;\/+]\s*|\s+[yYeE]\s+/u', $value) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $id = $this->find($part);
            if ($id === null) {
                return ['ids' => [], 'unknown' => $part];
            }
            $ids[] = $id;
        }

        return ['ids' => array_values(array_unique($ids)), 'unknown' => $ids === [] ? $value : null];
    }

    /**
     * Ids of the companies a free text names ("la VPN de Derivados…").
     *
     * @return list<int>
     */
    public function mentioned(string $text): array
    {
        $normalized = ' '.ImportFields::normalize($text).' ';
        $ids = [];

        foreach ($this->active() as $company) {
            foreach ($this->spellings($company) as $spelling) {
                if (str_contains($normalized, ' '.$spelling.' ')) {
                    $ids[] = (int) $company->id;
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Short names joined as people say them ("Segurex y Derivados").
     *
     * @param  list<int>  $ids
     */
    public function names(array $ids): string
    {
        $names = array_values(array_filter(array_map(
            fn (int $id) => $this->active()->get($id)?->short_name ?? Company::query()->find($id)?->short_name,
            $ids,
        )));

        return self::join($names, 'y');
    }

    /**
     * "A", "A y B", "A, B y C" (or "o").
     *
     * @param  list<string>  $items
     */
    public static function join(array $items, string $last = 'y'): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $tail = array_pop($items);

        return implode(', ', $items)." {$last} ".$tail;
    }

    private function find(string $part): ?int
    {
        $normalized = ImportFields::normalize($part);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->active() as $company) {
            foreach ([$company->name, $company->short_name, $company->slug] as $name) {
                if (ImportFields::normalize((string) $name) === $normalized) {
                    return (int) $company->id;
                }
            }
        }

        $matches = $this->active()->filter(fn (Company $company) => in_array($normalized, $this->spellings($company), true));

        return $matches->count() === 1 ? (int) $matches->first()->id : null;
    }

    /**
     * @return list<string>
     */
    private function spellings(Company $company): array
    {
        $spellings = [ImportFields::normalize((string) $company->short_name), ImportFields::normalize((string) $company->slug)];

        foreach ([$company->name, $company->short_name] as $name) {
            foreach (explode(' ', ImportFields::normalize((string) $name)) as $word) {
                if (mb_strlen($word) >= 5 && ! in_array($word, self::GENERIC, true)) {
                    $spellings[] = $word;
                }
            }
        }

        return array_values(array_unique(array_filter($spellings)));
    }
}
