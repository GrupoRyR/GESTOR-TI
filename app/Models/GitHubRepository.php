<?php

namespace App\Models;

use Database\Factories\GitHubRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A repository the GitHub App is installed on (Stage D, `GitHubRepo` in
 * types/github.ts). Not company data: GitHub is one organization for the
 * whole group. Optionally linked to one product or project (`linkable`).
 *
 * @property int $id
 * @property int $github_id
 * @property string $full_name
 * @property string $name
 * @property string $default_branch
 * @property bool $private
 * @property string $html_url
 * @property ?string $language
 * @property ?Carbon $pushed_at
 * @property ?list<string> $branches
 * @property ?int $installation_id
 * @property ?Carbon $last_synced_at
 * @property ?array<string, array<string, mixed>> $ci latest CI run per branch (`CiRun`)
 * @property ?array<string, mixed> $last_deploy last push to the linked branch (`Deploy` without provider)
 * @property ?list<array<string, mixed>> $recent_commits newest first (`CommitSummary`)
 * @property ?string $linkable_type
 * @property ?int $linkable_id
 * @property ?string $branch
 * @property bool $read_docs
 * @property bool $receive_events
 * @property ?int $linked_by_id
 * @property ?Carbon $linked_at
 */
#[Fillable([
    'github_id', 'full_name', 'name', 'default_branch', 'private', 'html_url', 'language', 'pushed_at', 'branches',
    'installation_id', 'last_synced_at',
])]
class GitHubRepository extends Model
{
    /** @use HasFactory<GitHubRepositoryFactory> */
    use HasFactory;

    protected $table = 'github_repositories';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_id' => 'integer',
            'private' => 'boolean',
            'pushed_at' => 'datetime',
            'branches' => 'array',
            'installation_id' => 'integer',
            'last_synced_at' => 'datetime',
            'ci' => 'array',
            'last_deploy' => 'array',
            'recent_commits' => 'array',
            'read_docs' => 'boolean',
            'receive_events' => 'boolean',
            'linked_at' => 'datetime',
        ];
    }

    /**
     * The product (AiProduct) or project (AiProject) it is linked to.
     *
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_id');
    }

    public function isLinked(): bool
    {
        return $this->linkable_type !== null && $this->linkable_id !== null;
    }

    /**
     * `product` | `project` (design `linked_to.kind`); null when unlinked.
     */
    public function linkKind(): ?string
    {
        return match ($this->linkable_type) {
            AiProduct::class => 'product',
            AiProject::class => 'project',
            default => null,
        };
    }

    /**
     * Links it to a product or project, replacing any previous link.
     */
    public function linkTo(AiProduct|AiProject $target, ?string $branch, ?User $linkedBy, bool $readDocs = true, bool $receiveEvents = true): void
    {
        $this->forceFill([
            'linkable_type' => $target::class,
            'linkable_id' => $target->getKey(),
            'branch' => $branch ?? $this->default_branch,
            'read_docs' => $readDocs,
            'receive_events' => $receiveEvents,
            'linked_by_id' => $linkedBy?->getKey(),
            'linked_at' => now(),
        ])->save();
    }

    /**
     * Branch used for CI and deploys: the linked branch, else the default one.
     */
    public function trackedBranch(): string
    {
        return $this->branch ?? $this->default_branch;
    }

    /**
     * Branch names, default branch first, without repeats.
     *
     * @return list<string>
     */
    public function branchNames(): array
    {
        return array_values(array_unique([$this->default_branch, ...($this->branches ?? [])]));
    }
}
