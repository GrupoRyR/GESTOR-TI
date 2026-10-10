<?php

namespace App\Support\Ai\Presenters;

use App\Domain\Ai\DocumentTemplates;
use App\Models\AiDocument;
use App\Models\AiDocumentRevision;
use Illuminate\Support\Collection;

/**
 * Documentation shapes of resources/js/types/ai.ts and ai-knowledge.ts:
 * `DocSummary`, `DocListItem`, `Doc` and `DocVersion`. Callers eager load
 * `RELATIONS`.
 */
final class DocumentPresenter
{
    /** Relations `summary()`/`listItem()` read. */
    public const RELATIONS = ['project', 'product', 'updatedBy.employee'];

    /**
     * `DocSummary`.
     *
     * @return array<string, mixed>
     */
    public static function summary(AiDocument $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'template' => $document->template,
            'owner' => self::owner($document),
            'version' => $document->version,
            'not_applicable' => $document->not_applicable,
            'updated_at' => $document->updated_at->toIso8601String(),
        ];
    }

    /**
     * `DocListItem`.
     *
     * @return array<string, mixed>
     */
    public static function listItem(AiDocument $document): array
    {
        return [...self::summary($document), 'updated_by' => self::updatedBy($document)];
    }

    /**
     * `Doc`.
     *
     * @return array<string, mixed>
     */
    public static function doc(AiDocument $document): array
    {
        return [
            ...self::summary($document),
            'markdown' => $document->body,
            'updated_by' => self::updatedBy($document),
            'visibility' => $document->visibility,
            'repository_path' => $document->repository_path,
        ];
    }

    /**
     * `DocVersion`.
     *
     * @return array<string, mixed>
     */
    public static function version(AiDocumentRevision $revision, int $current): array
    {
        return [
            'version' => $revision->version,
            'summary' => $revision->summary,
            'author' => $revision->user !== null ? RequestPresenter::person($revision->user) : self::nobody(),
            'created_at' => ($revision->created_at ?? now())->toIso8601String(),
            'current' => $revision->version === $current,
        ];
    }

    /**
     * `{ kind, key, name }`: product slug or project key.
     *
     * @return array{kind: string, key: string, name: string}
     */
    public static function owner(AiDocument $document): array
    {
        $owner = $document->owner();

        return [
            'kind' => $document->ownerKind(),
            'key' => (string) ($document->ai_project_id !== null ? $document->project?->key : $document->product?->slug),
            'name' => (string) $owner?->name,
        ];
    }

    /**
     * Tree order: products first, then projects; by owner name, template
     * order and title. The page groups by owner and keeps this order.
     *
     * @param  Collection<int, AiDocument>  $documents
     * @return Collection<int, AiDocument>
     */
    public static function sort(Collection $documents): Collection
    {
        return $documents->sortBy([
            fn (AiDocument $a, AiDocument $b) => ($a->ownerKind() === AiDocument::KIND_PRODUCT ? 0 : 1) <=> ($b->ownerKind() === AiDocument::KIND_PRODUCT ? 0 : 1),
            fn (AiDocument $a, AiDocument $b) => strcasecmp((string) $a->owner()?->name, (string) $b->owner()?->name),
            fn (AiDocument $a, AiDocument $b) => (int) $a->ai_project_id + (int) $a->ai_product_id <=> (int) $b->ai_project_id + (int) $b->ai_product_id,
            fn (AiDocument $a, AiDocument $b) => DocumentTemplates::order($a->template) <=> DocumentTemplates::order($b->template),
            fn (AiDocument $a, AiDocument $b) => strcasecmp($a->title, $b->title),
        ])->values();
    }

    /**
     * @return array<string, mixed>
     */
    private static function updatedBy(AiDocument $document): array
    {
        return $document->updatedBy !== null ? RequestPresenter::person($document->updatedBy) : self::nobody();
    }

    /**
     * A deleted user: the design needs a PersonRef.
     *
     * @return array{id: int, name: string, job_title: null, avatar_url: null}
     */
    private static function nobody(): array
    {
        return ['id' => 0, 'name' => 'Usuario eliminado', 'job_title' => null, 'avatar_url' => null];
    }
}
