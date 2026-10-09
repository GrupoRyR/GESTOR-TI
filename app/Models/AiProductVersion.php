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
 */
#[Fillable(['ai_product_id', 'version', 'released_at', 'summary', 'notes', 'source', 'release_url'])]
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
            ->withTimestamps();
    }

    public function isCurrent(): bool
    {
        return $this->product !== null && $this->product->current_version === $this->version;
    }
}
