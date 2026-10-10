<?php

namespace App\Models;

use App\Enums\Ai\ProductVersionSource;
use App\Models\Scopes\CompanyScope;
use Database\Factories\AiProductVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A released version of a product with its notes (markdown) and the
 * requests it delivered (`ai_product_version_request`). Typed by TI or
 * imported from a GitHub release (Stage D).
 *
 * @property int $id
 * @property int $ai_product_id
 * @property string $version
 * @property ?Carbon $released_at
 * @property ?string $summary
 * @property ?string $notes
 * @property ProductVersionSource $source
 * @property ?string $release_url
 * @property ?string $tag "v1.8.2" (GitHub release)
 * @property ?int $github_release_id
 * @property ?int $github_repository_id
 * @property ?Carbon $published_at
 * @property ?string $author_login
 * @property ?string $technical_notes release body as GitHub sent it
 * @property ?string $user_notes notes IT confirmed for requesters (Stage D4)
 * @property ?Carbon $notified_at requesters notified (Stage D4)
 */
#[Fillable([
    'ai_product_id', 'version', 'released_at', 'summary', 'notes', 'source', 'release_url', 'tag', 'github_release_id',
    'github_repository_id', 'published_at', 'author_login', 'technical_notes', 'user_notes', 'notified_at',
])]
class AiProductVersion extends Model
{
    /** @use HasFactory<AiProductVersionFactory> */
    use HasFactory;

    protected $attributes = [
        'source' => 'manual',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ProductVersionSource::class,
            'released_at' => 'date',
            'github_release_id' => 'integer',
            'published_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(AiProduct::class, 'ai_product_id')->withTrashed();
    }

    /**
     * Requests included in this version, from every company of the product.
     *
     * @return BelongsToMany<AiRequest, $this>
     */
    public function requests(): BelongsToMany
    {
        return $this->belongsToMany(AiRequest::class, 'ai_product_version_request')
            ->withoutGlobalScope(CompanyScope::class)
            ->withPivot('found_in_pr')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<GitHubRepository, $this>
     */
    public function githubRepository(): BelongsTo
    {
        return $this->belongsTo(GitHubRepository::class);
    }

    public function isCurrent(): bool
    {
        return $this->product !== null && $this->product->current_version === $this->version;
    }
}
