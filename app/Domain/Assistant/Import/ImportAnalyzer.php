<?php

namespace App\Domain\Assistant\Import;

use App\Domain\Ai\SecretGuard;
use App\Domain\Assistant\CompanyNames;
use App\Domain\Assistant\KnowledgeTypes;
use App\Models\Area;
use App\Models\KnowledgeImport;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Decides what each row of the mapped sheet does (step 3 of the import),
 * without saving anything. Every row enters as the sheet type.
 *
 * - Title, company and visibility are required; area, owner, description
 *   and every type field are optional (lib/importMapping.ts). Companies are
 *   parsed by App\Domain\Assistant\CompanyNames and must be ones the person
 *   may act on; the area must belong to one of them; the owner is an
 *   active user by name or email (a "[DUEÑO]" placeholder leaves it empty).
 * - Matching (documented decision): a `key` column ("K-031") updates that
 *   card (it must exist, be visible and have the sheet type); otherwise a
 *   visible card of the same type with the same title (ignoring accents,
 *   case and punctuation) is updated; else the row is new.
 * - An update changes only what the row brings: an empty cell keeps the
 *   card's value (it never erases).
 * - Two rows for the same card or the same title: the later one is an error.
 * - "Nunca contraseñas": a cell that looks like a secret makes the row an
 *   error (App\Domain\Ai\SecretGuard).
 * - One error per row (the first found), with the cell to fix.
 */
final class ImportAnalyzer
{
    private const VISIBILITY_WORDS = [
        'company' => ['toda la empresa', 'empresa', 'company', 'todos', 'publica', 'publico', 'general'],
        'it' => ['solo ti', 'ti', 'it', 'interna', 'interno', 'privada', 'solo it'],
    ];

    private const VISIBILITY_LABELS = ['it' => 'Solo TI', 'company' => 'Toda la empresa'];

    /** Labels of the common fields in the list of changes. */
    private const CHANGE_LABELS = [
        'title' => 'Título',
        'company' => 'Empresa',
        'area' => 'Área',
        'description' => 'Descripción',
        'visibility' => 'Visibilidad',
        'owner' => 'Dueño',
    ];

    public function __construct(
        private readonly Spreadsheet $spreadsheet,
        private readonly SecretGuard $guard,
        private readonly CurrentCompany $tenancy,
        private readonly CompanyNames $companies,
    ) {}

    /**
     * Rows of the sheet in Excel order (blank rows skipped).
     *
     * @return list<ImportRow>
     *
     * @throws UnreadableSpreadsheet
     */
    public function analyze(KnowledgeImport $import, User $user, string $path): array
    {
        $type = (string) $import->type;
        $columns = $import->mapping ?? [];
        $index = [];
        foreach ($columns as $position => $column) {
            if ($column['field'] !== null) {
                $index[$column['field']] = $position;
            }
        }

        $lookups = $this->lookups($user, $type);
        $reviewAt = CarbonImmutable::today()->addMonthsNoOverflow(KnowledgeTypes::REVIEW_MONTHS)->toDateString();
        $results = [];
        $claimed = [];
        $titles = [];
        $skipHeader = $import->header_row;

        try {
            foreach ($this->spreadsheet->rows($path, $import->extension(), (string) $import->sheet) as $number => $cells) {
                if ($skipHeader) {
                    $skipHeader = false;

                    continue;
                }

                $cells = array_pad(array_slice($cells, 0, count($columns)), count($columns), '');
                if ($this->blank($cells, $index)) {
                    continue;
                }

                $row = $this->row($number, $cells, $index, $type, $user, $lookups, $reviewAt);

                if ($row->outcome !== 'error') {
                    $row = $this->deduplicate($row, $claimed, $titles);
                }

                $results[] = $row;
            }
        } catch (UnreadableSpreadsheet $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UnreadableSpreadsheet($exception->getMessage(), 0, $exception);
        }

        return $results;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $index
     * @param  array<string, mixed>  $lookups
     */
    private function row(int $number, array $cells, array $index, string $type, User $user, array $lookups, string $reviewAt): ImportRow
    {
        $cell = fn (string $field): string => isset($index[$field]) ? trim($cells[$index[$field]]) : '';
        $ref = fn (string $field): string => isset($index[$field]) ? Spreadsheet::column($index[$field]).$number : "fila {$number}";

        $title = $cell('title');
        $companyText = $cell('company');
        $areaText = $cell('area');
        $rawContext = ($companyText !== '' ? $companyText : 'Sin empresa').($areaText !== '' ? " · {$areaText}" : '');
        $displayTitle = $title !== '' ? $title : '(sin título)';
        $fail = fn (string $message, string $fix, ?string $context = null) => ImportRow::failed($number, $displayTitle, $context ?? $rawContext, $message, $fix, $cells);

        if ($title === '') {
            return $fail('Falta el título.', "Escribe el título en la celda {$ref('title')}, guarda y vuelve a subir el archivo.");
        }
        if (mb_strlen($title) > 160) {
            return $fail('El título tiene más de 160 caracteres.', "Acórtalo en la celda {$ref('title')}, guarda y vuelve a subir el archivo.");
        }

        $typeText = $cell('type');
        if ($typeText !== '' && ImportFields::typeFromName($typeText) !== $type) {
            $label = ImportFields::TYPE_LABELS[$type];

            return $fail(
                "La fila es de tipo «{$typeText}» y esta hoja importa fichas de tipo {$label}.",
                "Cambia la celda {$ref('type')} a {$label} o pasa la fila a la hoja de su tipo.",
            );
        }

        // Companies.
        $companyFix = "En la celda {$ref('company')} escribe {$lookups['company_hint']} (varias, separadas por coma), guarda y vuelve a subir el archivo.";
        if ($companyText === '') {
            return $fail('Falta la empresa.', $companyFix);
        }
        $parsed = $this->companies->parse($companyText, $lookups['accessible']);
        if ($parsed['unknown'] !== null) {
            return $fail("La empresa «{$parsed['unknown']}» no existe en el Gestor.", $companyFix, "Empresa «{$companyText}»".($areaText !== '' ? " · {$areaText}" : ''));
        }
        $companyIds = $parsed['ids'];
        foreach ($companyIds as $companyId) {
            if (! in_array($companyId, $lookups['accessible'], true)) {
                $name = $this->companies->names([$companyId]);

                return $fail("No puedes importar fichas de {$name}.", "Quita {$name} de la celda {$ref('company')}, guarda y vuelve a subir el archivo.");
            }
        }
        $companyNames = $this->companies->names($companyIds);

        // Area.
        $area = null;
        if ($areaText !== '') {
            $area = $this->area($lookups['areas'], $companyIds, $areaText);
            if ($area === null) {
                return $fail("El área «{$areaText}» no existe en {$companyNames}.", "En la celda {$ref('area')} escribe un área de esa empresa o déjala vacía.");
            }
        }

        // Visibility.
        $visibilityText = $cell('visibility');
        $visibility = $this->visibility($visibilityText);
        if ($visibility === null) {
            return $fail(
                $visibilityText === '' ? 'Falta la visibilidad.' : "La visibilidad «{$visibilityText}» no es válida.",
                "En la celda {$ref('visibility')} escribe Solo TI o Toda la empresa.",
            );
        }

        // Owner.
        $ownerText = $cell('owner');
        $owner = null;
        $ownerName = null;
        if ($ownerText !== '' && KnowledgeTypes::isPlaceholder($ownerText)) {
            $ownerName = $ownerText;
        } elseif ($ownerText !== '') {
            $owner = $lookups['users'][ImportFields::normalize($ownerText)] ?? $lookups['users'][mb_strtolower($ownerText)] ?? null;
            if ($owner === null) {
                return $fail("No hay un usuario «{$ownerText}» en el Gestor.", "En la celda {$ref('owner')} escribe el nombre de un usuario o déjala vacía.");
            }
            $ownerName = $owner->name;
        }

        // Card to update.
        $card = null;
        $keyText = $cell('key');
        if ($keyText !== '') {
            $card = $lookups['by_key'][mb_strtoupper($keyText)] ?? null;
            if ($card === null) {
                return $fail("La ficha «{$keyText}» no existe en el Gestor.", "Borra la clave de la celda {$ref('key')} para crearla como nueva.");
            }
            if ($card->type !== $type) {
                return $fail(
                    "La ficha {$card->key} es de tipo ".ImportFields::TYPE_LABELS[$card->type].'.',
                    "Pasa la fila a la hoja de ese tipo o borra la clave de la celda {$ref('key')}.",
                );
            }
        }
        $card ??= $lookups['by_title'][ImportFields::normalize($title)] ?? null;

        // Text lengths and secrets.
        $description = $cell('description');
        $fields = [];
        $texts = ['title' => $title, 'description' => $description];
        foreach (array_keys(KnowledgeTypes::fields($type)) as $key) {
            $value = $cell($key);
            $texts[$key] = $value;
            if ($value === '') {
                continue;
            }
            $fields[$key] = $key === KnowledgeTypes::LIST_FIELD ? self::steps($value) : $value;
        }
        foreach ($texts as $field => $text) {
            if (mb_strlen($text) > 5000) {
                return $fail('Una celda tiene más de 5.000 caracteres.', "Acorta la celda {$ref($field)}, guarda y vuelve a subir el archivo.");
            }
            if ($text !== '' && $this->guard->find($text) !== null) {
                return $fail('La fila parece tener una contraseña o una llave.', "Bórrala de la celda {$ref($field)}: las credenciales van en Accesos o en la bóveda, nunca en una ficha.");
            }
        }

        $context = $companyNames.($area !== null ? " · {$area->name}" : '');

        if ($card === null) {
            $data = [
                'type' => $type,
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'fields' => KnowledgeTypes::normalize($type, $fields),
                'company_ids' => $companyIds,
                'area_id' => $area?->id,
                'owner_id' => $owner?->id,
                'visibility' => $visibility,
                'review_at' => $reviewAt,
                'related_card_keys' => [],
                'related_product_slugs' => [],
            ];

            return new ImportRow($number, 'new', $title, $context, null, [], $visibility, $ownerName, null, $data, $cells);
        }

        return $this->update($number, $cells, $card, $type, $title, $description, $fields, $companyIds, $area, $visibility, $owner, $ownerName, $index);
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, mixed>  $fields
     * @param  list<int>  $companyIds
     * @param  array<string, int>  $index
     */
    private function update(int $number, array $cells, KnowledgeItem $card, string $type, string $title, string $description, array $fields, array $companyIds, ?Area $area, string $visibility, ?User $owner, ?string $ownerName, array $index): ImportRow
    {
        $currentCompanies = $card->companies->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $currentFields = KnowledgeTypes::normalize($type, $card->fields ?? []);
        $newFields = KnowledgeTypes::normalize($type, [...$currentFields, ...$fields]);

        $areaId = $area?->id;
        if ($area === null && $card->area !== null && in_array((int) $card->area->company_id, $companyIds, true)) {
            $areaId = $card->area_id;
            $area = $card->area;
        }
        $ownerId = $owner?->id ?? $card->owner_id;
        $ownerName ??= $card->owner?->name;

        $data = [
            'type' => $type,
            'title' => $title,
            'description' => $description !== '' ? $description : $card->description,
            'fields' => $newFields,
            'company_ids' => $companyIds,
            'area_id' => $areaId,
            'owner_id' => $ownerId,
            'visibility' => $visibility,
            'review_at' => $card->review_at->toDateString(),
            'related_card_keys' => $card->relatedItems()->pluck('key')->all(),
            'related_product_slugs' => $card->relatedProducts()->pluck('slug')->all(),
        ];

        $sortedIds = $companyIds;
        sort($sortedIds);
        $before = [
            'title' => $card->title,
            'company' => $this->companies->names($currentCompanies),
            'area' => $card->area?->name,
            'description' => $card->description,
            'visibility' => self::VISIBILITY_LABELS[$card->visibility] ?? $card->visibility,
            'owner' => $card->owner?->name,
        ];
        $after = [
            'title' => $title,
            'company' => $sortedIds === $currentCompanies ? $before['company'] : $this->companies->names($companyIds),
            'area' => $area?->name,
            'description' => $data['description'],
            'visibility' => self::VISIBILITY_LABELS[$visibility],
            'owner' => $ownerId === $card->owner_id ? $before['owner'] : $ownerName,
        ];

        $changes = [];
        foreach (ImportFields::keys($type) as $key) {
            if (isset(self::CHANGE_LABELS[$key])) {
                if ($before[$key] !== $after[$key] && $after[$key] !== null) {
                    $changes[] = ['label' => self::CHANGE_LABELS[$key], 'from' => $before[$key], 'to' => $after[$key]];
                }

                continue;
            }
            if (! array_key_exists($key, $newFields)) {
                continue;
            }
            $from = self::display($currentFields[$key]);
            $to = self::display($newFields[$key]);
            if ($from !== $to && $to !== null) {
                $changes[] = ['label' => ImportFields::label($type, $key), 'from' => $from, 'to' => $to];
            }
        }

        $context = $this->companies->names($companyIds).($area !== null ? " · {$area->name}" : '');

        return new ImportRow($number, 'update', $title, $context, $card, $changes, $visibility, $ownerName, null, $data, $cells);
    }

    /**
     * A later row for a card or a title already taken becomes an error.
     *
     * @param  array<int, array{row: int, key: string}>  $claimed
     * @param  array<string, array{row: int, key: ?string}>  $titles
     */
    private function deduplicate(ImportRow $row, array &$claimed, array &$titles): ImportRow
    {
        $title = ImportFields::normalize($row->title);
        $earlier = $row->card !== null ? ($claimed[$row->card->id] ?? null) : null;
        $earlier ??= $titles[$title] ?? null;

        if ($earlier !== null) {
            return ImportRow::failed(
                $row->row,
                $row->title,
                $row->context,
                $earlier['key'] !== null ? "La fila {$earlier['row']} ya actualiza {$earlier['key']}." : "La fila {$earlier['row']} ya crea una ficha con este título.",
                $earlier['key'] !== null ? 'Deja una sola fila para esa ficha, guarda y vuelve a subir el archivo.' : 'Deja una sola fila con ese título, guarda y vuelve a subir el archivo.',
                $row->cells,
            );
        }

        $entry = ['row' => $row->row, 'key' => $row->card?->key];
        if ($row->card !== null) {
            $claimed[$row->card->id] = $entry;
        }
        $titles[$title] = $entry;

        return $row;
    }

    /**
     * Data the rows are checked against.
     *
     * @return array<string, mixed>
     */
    private function lookups(User $user, string $type): array
    {
        $accessible = $this->companies->active()->keys()
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $this->tenancy->canAccess($user, $id))
            ->values()->all();

        $cards = KnowledgeItem::query()->visibleTo($user)->with(['companies:id', 'area', 'owner'])->orderBy('id')->get();
        $byTitle = [];
        foreach ($cards->where('type', $type) as $card) {
            $byTitle[ImportFields::normalize($card->title)] ??= $card;
        }

        $users = [];
        foreach (User::query()->where('active', true)->orderBy('id')->get(['id', 'name', 'email']) as $person) {
            $users[ImportFields::normalize((string) $person->name)] ??= $person;
            if ($person->email !== null) {
                $users[mb_strtolower((string) $person->email)] ??= $person;
            }
        }

        return [
            'accessible' => $accessible,
            'company_hint' => CompanyNames::join(array_values(array_map(
                fn (int $id) => (string) $this->companies->active()->get($id)?->short_name,
                $accessible,
            )), 'o'),
            'areas' => Area::withoutGlobalScopes()->where('is_active', true)->orderBy('company_id')->orderBy('id')->get(),
            'users' => $users,
            'by_key' => $cards->keyBy(fn (KnowledgeItem $card) => mb_strtoupper($card->key))->all(),
            'by_title' => $byTitle,
        ];
    }

    /**
     * @param  Collection<int, Area>  $areas
     * @param  list<int>  $companyIds
     */
    private function area(Collection $areas, array $companyIds, string $name): ?Area
    {
        $normalized = ImportFields::normalize($name);

        foreach ($companyIds as $companyId) {
            $area = $areas->first(fn (Area $area) => (int) $area->company_id === $companyId && ImportFields::normalize($area->name) === $normalized);
            if ($area !== null) {
                return $area;
            }
        }

        return null;
    }

    private function visibility(string $text): ?string
    {
        $normalized = ImportFields::normalize($text);

        foreach (self::VISIBILITY_WORDS as $visibility => $words) {
            if (in_array($normalized, $words, true)) {
                return $visibility;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $index
     */
    private function blank(array $cells, array $index): bool
    {
        foreach ($index as $position) {
            if (trim($cells[$position] ?? '') !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * "1. Abrir SAP\n2. Ir a…" or "Abrir SAP; Ir a…" as a list of steps.
     *
     * @return list<string>
     */
    public static function steps(string $value): array
    {
        $parts = preg_split('/\r\n|\r|\n/', $value) ?: [];
        if (count($parts) === 1) {
            $parts = explode(';', $value);
        }

        return array_values(array_filter(array_map(
            fn (string $step) => trim((string) preg_replace('/^\s*\d+\s*[\.\)\-]\s*/u', '', $step)),
            $parts,
        ), fn (string $step) => $step !== ''));
    }

    private static function display(mixed $value): ?string
    {
        if (is_array($value)) {
            return $value === [] ? null : implode('; ', $value);
        }

        return $value === null || $value === '' ? null : (string) $value;
    }
}
