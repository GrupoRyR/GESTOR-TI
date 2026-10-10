<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\Documents;
use App\Domain\Ai\DocumentTemplates;
use App\Domain\Ai\LineDiff;
use App\Http\Controllers\Controller;
use App\Models\AiDocument;
use App\Models\AiDocumentRevision;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\User;
use App\Support\Ai\Presenters\DocumentPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Documentation (PROMPT 6.4.4, types/pages/ai-knowledge.ts). Pages need
 * `ai.manage` (routes) and AiDocumentPolicy; writing needs `ai.docs.edit`.
 *
 * - `Ai/Docs/Index` (`ai.docs.index`, and `ai.projects.docs` with only the
 *   project's docs): every doc whose owner is in the company context.
 * - `Ai/Docs/Create` + `ai.docs.store`: a doc from a template (version 1).
 * - `Ai/Docs/Edit` + `ai.docs.update` (autosave, partial reload
 *   `saved_at`/`version`) + `ai.docs.publish` (new version).
 * - `Ai/Docs/History` (`compare` partial with `?version=`) + `ai.docs.restore`.
 * - `Ai/Docs/Repo`: GitHub reading arrives in Stage D: `{ connected: false }`.
 *
 * Optional `version` in the update/publish bodies is the version the
 * editor started from: a stale one is refused (optimistic concurrency).
 */
class DocumentController extends Controller
{
    public function __construct(private readonly Documents $documents) {}

    public function index(Request $http): Response
    {
        $user = $this->user($http);
        Gate::authorize('viewAny', AiDocument::class);

        return Inertia::render('Ai/Docs/Index', [
            'docs' => $this->tree($user),
            'now' => now()->toIso8601String(),
        ]);
    }

    /**
     * ProjectLayout tab "Documentación": the same list, only this project.
     */
    public function project(Request $http, AiProject $project): Response
    {
        Gate::authorize('manage', $project);

        $docs = $project->documents()->with(DocumentPresenter::RELATIONS)->get();

        return Inertia::render('Ai/Docs/Index', [
            'docs' => DocumentPresenter::sort($docs)->map(fn (AiDocument $document) => DocumentPresenter::listItem($document))->all(),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function create(Request $http): Response
    {
        $user = $this->user($http);
        $owners = $this->owners($user);

        $kind = $http->query('owner_kind');
        $key = $http->query('owner_key');
        $valid = is_string($kind) && is_string($key)
            && collect($owners)->contains(fn (array $owner) => $owner['kind'] === $kind && $owner['key'] === $key);

        return Inertia::render('Ai/Docs/Create', [
            'owners' => $owners,
            'defaults' => $valid ? ['owner_kind' => $kind, 'owner_key' => $key] : null,
        ]);
    }

    public function store(Request $http): RedirectResponse
    {
        $user = $this->user($http);
        Gate::authorize('create', AiDocument::class);

        $owner = null;
        $validator = Validator::make($http->all(), [
            'template' => ['required', 'string', Rule::in(DocumentTemplates::KEYS)],
            'owner_kind' => ['required', Rule::in([AiDocument::KIND_PROJECT, AiDocument::KIND_PRODUCT])],
            'owner_key' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:160'],
        ], [
            'template.*' => 'Elige una plantilla.',
            'owner_kind.*' => 'Elige dónde va el documento.',
            'owner_key.*' => 'Elige dónde va el documento.',
            'title.required' => 'Escribe el título.',
            'title.max' => 'El título puede tener hasta 160 caracteres.',
        ]);
        $validator->after(function ($validator) use ($http, $user, &$owner): void {
            $key = $http->input('owner_key');
            $owner = match ($http->input('owner_kind')) {
                AiDocument::KIND_PROJECT => is_string($key) ? AiProject::query()->where('key', $key)->first() : null,
                AiDocument::KIND_PRODUCT => is_string($key) ? AiProduct::query()->visibleTo($user)->where('slug', $key)->first() : null,
                default => null,
            };

            if ($owner === null && ! $validator->errors()->has('owner_key')) {
                $validator->errors()->add('owner_key', 'Elige dónde va el documento.');
            }
        });
        $data = $validator->validate();

        if (! $owner instanceof AiProject && ! $owner instanceof AiProduct) {
            throw ValidationException::withMessages(['owner_key' => 'Elige dónde va el documento.']);
        }

        $document = $this->documents->create($owner, $data['template'], $data['title'], $user);

        return redirect()->route('ai.docs.edit', $document)->with('success', 'Documento creado.');
    }

    public function edit(Request $http, AiDocument $doc): Response
    {
        $user = $this->user($http);
        Gate::authorize('view', $doc);
        $doc->loadMissing(DocumentPresenter::RELATIONS);

        return Inertia::render('Ai/Docs/Edit', [
            'doc' => fn () => DocumentPresenter::doc($doc),
            'tree' => fn () => $this->tree($user),
            'saved_at' => $doc->updated_at->toIso8601String(),
            'version' => $doc->version,
            'company_id' => $doc->companyId() ?? (int) $user->company_id,
            'now' => now()->toIso8601String(),
        ]);
    }

    public function update(Request $http, AiDocument $doc): RedirectResponse
    {
        Gate::authorize('update', $doc);

        $data = $http->validate([
            'markdown' => ['present', 'nullable', 'string', 'max:500000'],
            'version' => ['nullable', 'integer', 'min:1'],
        ], ['markdown.*' => 'El documento es demasiado largo.']);

        $this->documents->saveDraft($doc, (string) ($data['markdown'] ?? ''), $this->user($http), isset($data['version']) ? (int) $data['version'] : null);

        return back();
    }

    public function publish(Request $http, AiDocument $doc): RedirectResponse
    {
        Gate::authorize('update', $doc);

        $data = $http->validate([
            'summary' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'integer', 'min:1'],
        ], ['summary.*' => 'Escribe qué cambió (hasta 255 caracteres).']);

        $published = $this->documents->publish($doc, $data['summary'], $this->user($http), isset($data['version']) ? (int) $data['version'] : null);

        return back()->with('success', "Publicaste la versión {$published->version}.");
    }

    public function history(Request $http, AiDocument $doc): Response
    {
        Gate::authorize('view', $doc);
        $doc->loadMissing(DocumentPresenter::RELATIONS);

        $revisions = $doc->revisions()->with('user.employee')->get();
        $asked = is_numeric($http->query('version')) ? (int) $http->query('version') : null;

        return Inertia::render('Ai/Docs/History', [
            'doc' => fn () => DocumentPresenter::summary($doc),
            'versions' => fn () => $revisions->map(fn (AiDocumentRevision $revision) => DocumentPresenter::version($revision, $doc->version))->values()->all(),
            'compare' => fn () => $this->compare($revisions, $doc->version, $asked),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function restore(Request $http, AiDocument $doc, int $version): RedirectResponse
    {
        Gate::authorize('update', $doc);

        /** @var AiDocumentRevision|null $revision */
        $revision = $doc->revisions()->where('version', $version)->first();
        abort_if($revision === null, 404);

        $restored = $this->documents->restore($doc, $revision, $this->user($http));

        return redirect()->route('ai.docs.history', $doc)
            ->with('success', "Restauraste la versión {$version} como versión {$restored->version}.");
    }

    /**
     * Read-only files from the product's GitHub repository (Stage D): not
     * connected yet. `repo` is also the partial reload of a file choice.
     */
    public function repo(Request $http): Response
    {
        $user = $this->user($http);
        Gate::authorize('viewAny', AiDocument::class);

        $products = AiProduct::query()->visibleTo($user)->orderBy('name')->orderBy('id')->get(['ai_products.id', 'slug', 'name']);
        $asked = (string) $http->query('product', '');
        $slug = $products->contains('slug', $asked) ? $asked : (string) $products->first()?->slug;

        return Inertia::render('Ai/Docs/Repo', [
            'products' => $products->map(fn (AiProduct $product) => ['slug' => $product->slug, 'name' => $product->name])->values()->all(),
            'product_slug' => $slug,
            'repo' => fn () => ['connected' => false],
            'now' => now()->toIso8601String(),
        ]);
    }

    /**
     * `DocCompare`: the chosen version (default: the one before the
     * current) against the current one, or against the previous one when
     * the current is chosen. Lines go older → newer. Null with one version.
     *
     * @param  Collection<int, AiDocumentRevision>  $revisions  newest first
     * @return array<string, mixed>|null
     */
    private function compare(Collection $revisions, int $current, ?int $asked): ?array
    {
        if ($revisions->count() < 2) {
            return null;
        }

        $byVersion = $revisions->keyBy('version');
        $currentRevision = $byVersion->get($current) ?? $revisions->first();
        $selected = $asked !== null && $byVersion->has($asked) ? $byVersion->get($asked) : $revisions->get(1);

        $against = $selected->version === $currentRevision->version
            ? $revisions->first(fn (AiDocumentRevision $revision) => $revision->version < $selected->version)
            : $currentRevision;

        if ($against === null) {
            return null;
        }

        [$older, $newer] = $selected->version < $against->version ? [$selected, $against] : [$against, $selected];

        return [
            'version' => $selected->version,
            'against' => $against->version,
            'lines' => LineDiff::lines($older->body, $newer->body),
            'markdown' => $selected->body,
        ];
    }

    /**
     * Docs of the context in tree order (`DocListItem`).
     *
     * @return list<array<string, mixed>>
     */
    private function tree(User $user): array
    {
        $docs = AiDocument::query()->visibleTo($user)->with(DocumentPresenter::RELATIONS)->get();

        return DocumentPresenter::sort($docs)->map(fn (AiDocument $document) => DocumentPresenter::listItem($document))->values()->all();
    }

    /**
     * `DocOwnerOption`: products of the context by name, then projects
     * newest first.
     *
     * @return list<array{kind: string, key: string, name: string}>
     */
    private function owners(User $user): array
    {
        $products = AiProduct::query()->visibleTo($user)->orderBy('name')->orderBy('id')->get(['ai_products.id', 'slug', 'name'])
            ->map(fn (AiProduct $product) => ['kind' => AiDocument::KIND_PRODUCT, 'key' => $product->slug, 'name' => $product->name]);
        $projects = AiProject::query()->latest('id')->get(['id', 'key', 'name'])
            ->map(fn (AiProject $project) => ['kind' => AiDocument::KIND_PROJECT, 'key' => $project->key, 'name' => $project->name]);

        return [...$products->all(), ...$projects->all()];
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
