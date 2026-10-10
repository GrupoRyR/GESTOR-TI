<?php

namespace App\Models;

use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A Gestor IA document (PROMPT 6.4.4): Markdown of a project or a product
 * (exactly one owner). `body` is the working copy the editor autosaves;
 * `version` is the last published version (`ai_document_revisions`).
 * Who may open or change it is `AiDocumentPolicy`; changes go through
 * App\Actions\Ai\Documents.
 *
 * @property int $id
 * @property ?int $ai_project_id
 * @property ?int $ai_product_id
 * @property string $title
 * @property string $slug
 * @property string $template
 * @property string $body
 * @property string $visibility
 * @property bool $not_applicable
 * @property ?string $repository_path
 * @property int $version
 * @property ?int $author_id
 * @property ?int $updated_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'ai_project_id', 'ai_product_id', 'title', 'slug', 'template', 'body', 'visibility', 'not_applicable',
    'repository_path', 'version', 'author_id', 'updated_by_id',
])]
class AiDocument extends Model
{
    use SoftDeletes;

    public const KIND_PROJECT = 'project';

    public const KIND_PRODUCT = 'product';

    /** `Doc.visibility`: everybody of the owner's companies, IT only, the project team. */
    public const VISIBILITIES = ['company', 'it', 'project'];

    protected $attributes = [
        'visibility' => 'it',
        'not_applicable' => false,
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'not_applicable' => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * Owner project from any company (the policy checks the context).
     *
     * @return BelongsTo<AiProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'ai_project_id')->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * @return BelongsTo<AiProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(AiProduct::class, 'ai_product_id')->withTrashed();
    }

    public function owner(): AiProject|AiProduct|null
    {
        return $this->ai_project_id !== null ? $this->project : $this->product;
    }

    public function ownerKind(): string
    {
        return $this->ai_project_id !== null ? self::KIND_PROJECT : self::KIND_PRODUCT;
    }

    /**
     * Company of the owner: the project's, or the product's primary one.
     */
    public function companyId(): ?int
    {
        if ($this->ai_project_id !== null) {
            return $this->project?->company_id;
        }

        $company = $this->product?->primaryCompany();

        return $company?->id;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /**
     * Published versions, newest first.
     *
     * @return HasMany<AiDocumentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(AiDocumentRevision::class)->orderByDesc('version');
    }

    /**
     * Docs whose owner the user sees in the current company context:
     * projects through CompanyScope, products through `visibleTo()`.
     * Callers still check `ai.manage` (documentation is for IT in Stage B).
     *
     * @param  Builder<AiDocument>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->whereIn('ai_project_id', AiProject::query()->select('id'))
                ->orWhereIn('ai_product_id', AiProduct::query()->visibleTo($user)->select('ai_products.id'));
        });
    }
}
