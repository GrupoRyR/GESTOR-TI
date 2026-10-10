<?php

namespace App\Actions\Ai;

use App\Domain\Ai\DocumentTemplates;
use App\Domain\Ai\SecretGuard;
use App\Models\AiDocument;
use App\Models\AiDocumentRevision;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Documentation changes (PROMPT 6.4.4, types/pages/ai-knowledge.ts):
 *
 * - create: a doc from a template for a project or product, published as
 *   version 1 ("Primera versión.").
 * - createProjectBase: the base set of a new project (PROJECT_BASE).
 * - saveDraft: the editor's autosave of the working copy; publish: adds
 *   the next version with its summary; restore: copies an old version into
 *   the working copy and publishes it as a new version (nothing deleted).
 *
 * Every body passes App\Domain\Ai\SecretGuard. Optimistic concurrency: when
 * the editor sends the version it started from and someone published a
 * newer one meanwhile, the save is refused (`markdown` error).
 * Authorization is the caller's (routes + AiDocumentPolicy).
 */
class Documents
{
    public const FIRST_SUMMARY = 'Primera versión.';

    public function __construct(private readonly SecretGuard $guard) {}

    public function create(AiProject|AiProduct $owner, string $template, string $title, User $actor, ?string $body = null): AiDocument
    {
        $name = $owner->name;
        $body ??= DocumentTemplates::body($template, $name);
        $this->guard->assertClean($body);

        return DB::transaction(function () use ($owner, $template, $title, $actor, $body): AiDocument {
            $title = mb_substr(trim($title), 0, 160);

            $document = AiDocument::create([
                'ai_project_id' => $owner instanceof AiProject ? $owner->id : null,
                'ai_product_id' => $owner instanceof AiProduct ? $owner->id : null,
                'title' => $title,
                'slug' => $this->slug($owner, $title),
                'template' => $template,
                'body' => $body,
                'visibility' => DocumentTemplates::visibility($template),
                'version' => 1,
                'author_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);

            $this->revision($document, 1, $body, self::FIRST_SUMMARY, $actor);

            return $document;
        });
    }

    /**
     * The base documentation of a new project, filled with what the
     * project knows (objective, origin request, acceptance criteria).
     */
    public function createProjectBase(AiProject $project, User $actor): void
    {
        $request = $project->originRequest();
        $criteria = $request?->criteria()->orderBy('position')->pluck('text')->all() ?? [];

        foreach (DocumentTemplates::PROJECT_BASE as $template) {
            $this->create(
                $project,
                $template,
                DocumentTemplates::title($template),
                $actor,
                DocumentTemplates::projectBody($template, $project->name, $project->objective, $request?->key, array_values($criteria)),
            );
        }
    }

    public function saveDraft(AiDocument $document, string $body, User $actor, ?int $baseVersion = null): AiDocument
    {
        $this->guard->assertClean($body);

        return DB::transaction(function () use ($document, $body, $actor, $baseVersion): AiDocument {
            $locked = $this->lock($document, $baseVersion);

            $locked->forceFill(['body' => $body, 'updated_by_id' => $actor->id]);
            // An unchanged body still counts as saved ("Guardado hace…").
            $locked->updated_at = now();
            $locked->save();

            return $locked;
        });
    }

    public function publish(AiDocument $document, string $summary, User $actor, ?int $baseVersion = null): AiDocument
    {
        return DB::transaction(function () use ($document, $summary, $actor, $baseVersion): AiDocument {
            $locked = $this->lock($document, $baseVersion);
            $this->guard->assertClean($locked->body);

            $version = $locked->version + 1;
            $this->revision($locked, $version, $locked->body, mb_substr(trim($summary), 0, 255), $actor);
            $locked->forceFill(['version' => $version, 'updated_by_id' => $actor->id])->save();

            return $locked;
        });
    }

    public function restore(AiDocument $document, AiDocumentRevision $revision, User $actor): AiDocument
    {
        return DB::transaction(function () use ($document, $revision, $actor): AiDocument {
            $locked = $this->lock($document, null);

            $version = $locked->version + 1;
            $this->revision($locked, $version, $revision->body, "Restaurada la versión {$revision->version}.", $actor);
            $locked->forceFill(['body' => $revision->body, 'version' => $version, 'updated_by_id' => $actor->id])->save();

            return $locked;
        });
    }

    /**
     * Fresh copy locked for the transaction; refuses a stale base version.
     */
    private function lock(AiDocument $document, ?int $baseVersion): AiDocument
    {
        /** @var AiDocument $locked */
        $locked = AiDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

        if ($baseVersion !== null && $baseVersion !== $locked->version) {
            throw ValidationException::withMessages([
                'markdown' => "Otra persona publicó la versión {$locked->version} mientras editabas. Recarga la página para ver sus cambios antes de guardar.",
            ]);
        }

        return $locked;
    }

    private function revision(AiDocument $document, int $version, string $body, string $summary, User $actor): AiDocumentRevision
    {
        return AiDocumentRevision::create([
            'ai_document_id' => $document->id,
            'version' => $version,
            'body' => $body,
            'summary' => $summary,
            'user_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Slug unique among the owner's docs (deleted ones included).
     */
    private function slug(AiProject|AiProduct $owner, string $title): string
    {
        $base = Str::slug($title) ?: 'documento';
        $column = $owner instanceof AiProject ? 'ai_project_id' : 'ai_product_id';
        $taken = AiDocument::withTrashed()->where($column, $owner->id)->pluck('slug')->all();

        $slug = $base;
        $n = 2;
        while (in_array($slug, $taken, true)) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
}
