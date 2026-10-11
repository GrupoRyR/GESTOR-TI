<?php

namespace App\Domain\Assistant;

use App\Models\KnowledgeItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters of the knowledge list and of the assistant's search
 * (`KnowledgeFilters` of types/pages/assistant-knowledge.ts). The query
 * string uses the Spanish keys of lib/knowledgeQuery.ts:
 * `q, tipo, empresa, area, visibilidad, vencidas`. Unknown values are
 * dropped; visibility and the overdue toggle are for managers only.
 */
final readonly class KnowledgeFilters
{
    public function __construct(
        public ?string $q = null,
        public ?string $type = null,
        public ?int $companyId = null,
        public ?int $areaId = null,
        public ?string $visibility = null,
        public bool $overdue = false,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query, bool $manage): self
    {
        $q = is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 120) : '';
        $type = $query['tipo'] ?? null;
        $visibility = $query['visibilidad'] ?? null;

        return new self(
            q: $q === '' ? null : $q,
            type: is_string($type) && in_array($type, KnowledgeTypes::TYPES, true) ? $type : null,
            companyId: self::id($query['empresa'] ?? null),
            areaId: self::id($query['area'] ?? null),
            visibility: $manage && is_string($visibility) && in_array($visibility, KnowledgeTypes::VISIBILITIES, true) ? $visibility : null,
            overdue: $manage && in_array($query['vencidas'] ?? null, ['1', 1, 'true', true], true),
        );
    }

    /**
     * Every filter except the free text (the caller searches `q`).
     *
     * @param  Builder<KnowledgeItem>  $query
     */
    public function apply(Builder $query, string $today): void
    {
        $query
            ->when($this->type !== null, fn (Builder $query) => $query->where('type', $this->type))
            ->when($this->companyId !== null, fn (Builder $query) => $query->whereHas('companies', fn (Builder $companies) => $companies->whereKey($this->companyId)))
            ->when($this->areaId !== null, fn (Builder $query) => $query->where('area_id', $this->areaId))
            ->when($this->visibility !== null, fn (Builder $query) => $query->where('visibility', $this->visibility))
            ->when($this->overdue, fn (Builder $query) => $query->whereDate('review_at', '<', $today));
    }

    /**
     * @return array{q: ?string, type: ?string, company_id: ?int, area_id: ?int, visibility: ?string, overdue: bool}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->q,
            'type' => $this->type,
            'company_id' => $this->companyId,
            'area_id' => $this->areaId,
            'visibility' => $this->visibility,
            'overdue' => $this->overdue,
        ];
    }

    private static function id(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }
}
