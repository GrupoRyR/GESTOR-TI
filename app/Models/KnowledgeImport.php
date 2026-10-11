<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Excel/CSV import of knowledge cards (PROMPT 6.8.1,
 * App\Actions\Assistant\KnowledgeImports). Belongs to the person who
 * uploaded it; nobody else sees it. Files live on the private disk.
 *
 * @property int $id
 * @property int $user_id
 * @property string $file_name
 * @property string $file_disk
 * @property ?string $file_path
 * @property ?int $file_size
 * @property string $status
 * @property list<array{name: string, first: list<string>, second: list<string>, count: int}> $sheets
 * @property ?string $sheet
 * @property bool $header_row
 * @property ?string $type
 * @property ?list<array{name: string, sample: string, field: ?string, match: string}> $mapping
 * @property ?array<string, mixed> $stats
 * @property ?string $error_path
 * @property ?string $error_message
 * @property ?CarbonImmutable $applied_at
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'user_id', 'file_name', 'file_disk', 'file_path', 'file_size', 'status', 'sheets', 'sheet', 'header_row',
    'type', 'mapping', 'stats', 'error_path', 'error_message', 'applied_at',
])]
class KnowledgeImport extends Model
{
    public const UNFINISHED = ['uploaded', 'mapped', 'previewed'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'sheets' => 'array',
            'header_row' => 'boolean',
            'mapping' => 'array',
            'stats' => 'array',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<KnowledgeImport>  $query
     */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', self::UNFINISHED);
    }

    public function isApplied(): bool
    {
        return $this->status === 'applied';
    }

    /**
     * File extension that decides the reader (`xlsx` or `csv`).
     */
    public function extension(): string
    {
        return strtolower(pathinfo((string) ($this->file_path ?? $this->file_name), PATHINFO_EXTENSION));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
