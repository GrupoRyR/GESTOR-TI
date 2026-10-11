<?php

namespace App\Models;

use App\Domain\Ai\Sequence;
use App\Domain\Assistant\KnowledgeTypes;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Factories\KnowledgeItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A knowledge card of the Asistente TI (PROMPT 6.8.1, types/assistant.ts
 * `KnowledgeCard`), keyed `K-031` and routed by its key.
 *
 * Not company-scoped by `CompanyScope`: a card may serve several companies
 * (`companies` pivot). Who may see it is `visibleTo($user)`:
 * - "Solo TI" (`it`) only for `assistant.knowledge.manage` holders;
 * - and, for everybody, only cards of the person's company context
 *   (CurrentCompany: all of them for `companies.view_all` on "Todas").
 *
 * Changes are logged by App\Actions\Assistant\KnowledgeItems (log
 * `knowledge`), with a Spanish sentence per change for "Cambios recientes".
 *
 * @property int $id
 * @property string $key
 * @property string $type
 * @property string $title
 * @property ?string $description
 * @property array<string, mixed> $fields
 * @property ?string $search_text
 * @property ?list<string> $tags
 * @property string $visibility
 * @property ?int $area_id
 * @property ?int $owner_id
 * @property CarbonImmutable $review_at
 * @property ?CarbonImmutable $last_reviewed_at
 * @property ?int $last_reviewed_by_id
 * @property bool $is_current
 * @property string $source
 * @property ?int $created_by_id
 * @property ?int $updated_by_id
 */
#[Fillable([
    'key', 'type', 'title', 'description', 'fields', 'tags', 'visibility', 'area_id', 'owner_id', 'review_at',
    'last_reviewed_at', 'last_reviewed_by_id', 'is_current', 'source', 'created_by_id', 'updated_by_id',
])]
class KnowledgeItem extends Model
{
    /** @use HasFactory<KnowledgeItemFactory> */
    use HasFactory, SoftDeletes;

    public const MANAGE_PERMISSION = 'assistant.knowledge.manage';

    public const USE_PERMISSION = 'assistant.use';

    public const SEQUENCE = 'knowledge_items';

    protected $attributes = [
        'visibility' => 'company',
        'is_current' => true,
        'source' => 'manual',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'tags' => 'array',
            'review_at' => 'immutable_date',
            'last_reviewed_at' => 'immutable_datetime',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (KnowledgeItem $item): void {
            if (blank($item->key)) {
                $item->key = self::formatKey(Sequence::next(self::SEQUENCE));
            }
        });

        static::saving(function (KnowledgeItem $item): void {
            $item->search_text = KnowledgeTypes::text($item->type, $item->fields ?? []);
        });
    }

    public static function formatKey(int $number): string
    {
        return 'K-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /**
     * Cards the user may see (see the class comment).
     *
     * @param  Builder<KnowledgeItem>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! self::canManage($user)) {
            if (! $user->can(self::USE_PERMISSION)) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('visibility', 'company');
        }

        $companies = app(CurrentCompany::class);
        $companyId = $companies->forUser($user);

        if ($companyId === null) {
            if (! $companies->canViewAll($user)) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $query->whereHas('companies', fn (Builder $companies) => $companies->whereKey($companyId));
    }

    public function isVisibleTo(User $user): bool
    {
        return self::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    public static function canManage(User $user): bool
    {
        return $user->can(self::MANAGE_PERMISSION);
    }

    /**
     * `ReviewStatus` of types/assistant.ts against today (`YYYY-MM-DD`).
     */
    public function reviewStatus(string $today): string
    {
        $days = (int) CarbonImmutable::parse($today)->startOfDay()->diffInDays($this->review_at->startOfDay(), false);

        return match (true) {
            $days < 0 => 'overdue',
            $days <= KnowledgeTypes::REVIEW_SOON_DAYS => 'due_soon',
            default => 'ok',
        };
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'knowledge_item_company');
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lastReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by_id');
    }

    /**
     * "Relacionadas" cards, in the order they were chosen. Written by
     * App\Actions\Assistant\KnowledgeItems (never `sync()`: products share
     * the table).
     *
     * @return BelongsToMany<KnowledgeItem, $this>
     */
    public function relatedItems(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'knowledge_links', 'knowledge_item_id', 'linked_item_id')
            ->withPivot('position')
            ->orderBy('knowledge_links.position');
    }

    /**
     * "Relacionadas" Gestor IA products (PROMPT 6.8.1: systems come from
     * the product catalog; they are linked, never copied into cards).
     *
     * @return BelongsToMany<AiProduct, $this>
     */
    public function relatedProducts(): BelongsToMany
    {
        return $this->belongsToMany(AiProduct::class, 'knowledge_links', 'knowledge_item_id', 'ai_product_id')
            ->withPivot('position')
            ->orderBy('knowledge_links.position');
    }
}
