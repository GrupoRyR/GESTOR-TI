<?php

namespace App\Domain\Assistant\Import;

use App\Models\KnowledgeItem;
use App\Support\Assistant\Presenters\KnowledgePresenter;

/**
 * Outcome of one Excel row (App\Domain\Assistant\Import\ImportAnalyzer):
 * a new card, an update of `$card`, or an error that skips the row.
 * `$data` is the KnowledgeItems payload to apply; `$cells` the raw row
 * (errors file).
 */
final readonly class ImportRow
{
    /**
     * @param  list<array{label: string, from: ?string, to: string}>  $changes
     * @param  array{message: string, fix: string}|null  $error
     * @param  array<string, mixed>|null  $data
     * @param  list<string>  $cells
     */
    public function __construct(
        public int $row,
        public string $outcome,
        public string $title,
        public string $context,
        public ?KnowledgeItem $card,
        public array $changes,
        public ?string $visibility,
        public ?string $ownerName,
        public ?array $error,
        public ?array $data,
        public array $cells,
    ) {}

    /**
     * @param  list<string>  $cells
     */
    public static function failed(int $row, string $title, string $context, string $message, string $fix, array $cells): self
    {
        return new self($row, 'error', $title, $context, null, [], null, null, ['message' => $message, 'fix' => $fix], null, $cells);
    }

    /**
     * `ImportRowResult` of types/assistant.ts.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'title' => $this->title,
            'context' => $this->context,
            'outcome' => $this->outcome,
            'card' => $this->card !== null ? KnowledgePresenter::ref($this->card) : null,
            'changes' => $this->changes,
            'visibility' => $this->visibility,
            'owner_name' => $this->ownerName,
            'error' => $this->error,
        ];
    }
}
