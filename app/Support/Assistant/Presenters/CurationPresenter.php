<?php

namespace App\Support\Assistant\Presenters;

use App\Actions\Assistant\KnowledgeImports;
use App\Domain\Ai\DuplicateFinder;
use App\Domain\Assistant\CompanyNames;
use App\Domain\Assistant\KnowledgeSearch;
use App\Domain\Assistant\KnowledgeTypes;
use App\Models\Area;
use App\Models\KnowledgeDraft;
use App\Models\KnowledgeImport;
use App\Models\KnowledgeItem;
use App\Models\UnansweredAsk;
use App\Models\UnansweredQuestion;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;

/**
 * Curation records as the shapes of types/assistant.ts: `ImportFile`,
 * `LearnDraft` (+ `DraftField`) and `UnansweredQuestion`.
 */
final class CurationPresenter
{
    /** A draft is "similar to" a card when it repeats most of the card's title words. */
    private const SIMILAR_SHARE = 0.6;

    public function __construct(
        private readonly KnowledgeSearch $search,
        private readonly CompanyNames $companies,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function importFile(KnowledgeImport $import): array
    {
        $sheets = array_map(function (array $sheet) use ($import): array {
            [$columns] = KnowledgeImports::sheetColumns($sheet, $import->header_row);

            return [
                'name' => $sheet['name'],
                'rows' => $import->header_row ? max(0, $sheet['count'] - 1) : $sheet['count'],
                'columns' => $sheet['first'] === [] ? [] : $columns,
            ];
        }, $import->sheets);

        return [
            'id' => $import->id,
            'name' => $import->file_name,
            'size_bytes' => $import->file_size,
            'uploaded_at' => $import->created_at->toIso8601String(),
            'sheets' => $sheets,
            'selected_sheet' => $import->sheet ?? ($import->sheets[0]['name'] ?? ''),
            'header_row' => $import->header_row,
        ];
    }

    /**
     * `LearnDraft` for a knowledge manager (similar and linked cards among
     * the cards they may see).
     *
     * @return array<string, mixed>
     */
    public function draft(KnowledgeDraft $draft, User $viewer): array
    {
        $proposed = $draft->proposed;
        $type = (string) $proposed['type'];
        $area = isset($proposed['area_id']) ? Area::withoutGlobalScopes()->find($proposed['area_id']) : null;

        return [
            'id' => $draft->id,
            'author' => self::person($draft->requestedBy),
            'channel' => $draft->source,
            'received_at' => $draft->created_at->toIso8601String(),
            'message' => $draft->message,
            'proposed' => [
                'type' => $type,
                'title' => (string) $proposed['title'],
                'description' => (string) ($proposed['description'] ?? ''),
                'company_ids' => array_values(array_map('intval', (array) ($proposed['company_ids'] ?? []))),
                'area' => KnowledgePresenter::area($area),
                'visibility' => (string) $proposed['visibility'],
                'review_at' => (string) $proposed['review_at'],
                'fields' => $this->draftFields($draft, $area),
            ],
            'similar_to' => $this->similar($draft, $viewer),
            'linked_card' => $this->linked($draft, $viewer),
        ];
    }

    /**
     * `UnansweredQuestion`: askers in the order they first asked.
     *
     * @return array<string, mixed>
     */
    public static function question(UnansweredQuestion $question): array
    {
        $askers = $question->asks
            ->sortBy('id')
            ->map(fn (UnansweredAsk $ask) => $ask->user)
            ->filter()
            ->unique('id')
            ->map(fn (User $user) => RequestPresenter::person($user))
            ->values()
            ->all();

        return [
            'id' => $question->id,
            'text' => $question->question,
            'times_asked' => $question->times_asked,
            'askers' => $askers,
            'last_asked_at' => $question->last_asked_at->toIso8601String(),
        ];
    }

    /**
     * `PersonRef` of a draft author (a deleted user keeps the draft).
     *
     * @return array<string, mixed>
     */
    public static function person(?User $user): array
    {
        return $user !== null
            ? RequestPresenter::person($user)
            : ['id' => 0, 'name' => 'Usuario eliminado', 'job_title' => null, 'avatar_url' => null];
    }

    /**
     * Empresa, Área, the type fields with a value or required, Dueño.
     *
     * @return list<array{label: string, value: ?string, origin: string}>
     */
    private function draftFields(KnowledgeDraft $draft, ?Area $area): array
    {
        $proposed = $draft->proposed;
        $type = (string) $proposed['type'];
        $origins = (array) ($proposed['origins'] ?? []);
        $companyIds = array_values(array_map('intval', (array) ($proposed['company_ids'] ?? [])));
        $rows = [];

        $rows[] = $companyIds === []
            ? ['label' => 'Empresa', 'value' => null, 'origin' => 'missing']
            : ['label' => 'Empresa', 'value' => $this->companies->names($companyIds), 'origin' => $origins['company'] ?? 'assumed'];

        if ($area !== null) {
            $rows[] = ['label' => 'Área', 'value' => $area->name, 'origin' => $origins['area'] ?? 'assumed'];
        }

        $fields = KnowledgeTypes::normalize($type, (array) ($proposed['fields'] ?? []));
        foreach (KnowledgeTypes::fields($type) as $key => [$label, $required]) {
            $value = $fields[$key];
            if (is_array($value)) {
                $value = $value === [] ? null : implode('; ', $value);
            }
            if ($value !== null) {
                $rows[] = ['label' => $label, 'value' => $value, 'origin' => $origins[$key] ?? 'message'];
            } elseif ($required) {
                $rows[] = ['label' => $label, 'value' => null, 'origin' => 'missing'];
            }
        }

        $owner = isset($proposed['owner_id']) ? User::query()->find($proposed['owner_id']) : null;
        $rows[] = $owner !== null
            ? ['label' => 'Dueño', 'value' => $owner->name, 'origin' => $origins['owner'] ?? 'message']
            : ['label' => 'Dueño', 'value' => null, 'origin' => 'missing'];

        return $rows;
    }

    /**
     * Probable duplicate: the best search hit whose title words are mostly
     * in the draft title.
     *
     * @return array<string, string>|null
     */
    private function similar(KnowledgeDraft $draft, User $viewer): ?array
    {
        $title = (string) ($draft->proposed['title'] ?? '');
        $words = DuplicateFinder::keywords($title);
        if ($words === []) {
            return null;
        }

        foreach ($this->search->search($viewer, $title, null, 3) as $hit) {
            $cardWords = DuplicateFinder::keywords($hit['item']->title);
            $shared = count(array_intersect($cardWords, $words));
            if ($cardWords !== [] && $shared >= 2 && $shared / count($cardWords) >= self::SIMILAR_SHARE) {
                return KnowledgePresenter::ref($hit['item']);
            }
        }

        return null;
    }

    /**
     * Card the text names by key ("enlazado a K-040").
     *
     * @return array<string, string>|null
     */
    private function linked(KnowledgeDraft $draft, User $viewer): ?array
    {
        if (preg_match('/\bK-\d{3,}\b/i', $draft->message, $match) !== 1) {
            return null;
        }

        $card = KnowledgeItem::query()->visibleTo($viewer)->where('key', mb_strtoupper($match[0]))->first();

        return $card !== null ? KnowledgePresenter::ref($card) : null;
    }
}
