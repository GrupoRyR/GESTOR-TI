<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\CreateRequest;
use App\Domain\Ai\DuplicateFinder;
use App\Domain\Ai\ProductResolver;
use App\Domain\Ai\Roadmap;
use App\Enums\Ai\RequestType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\StoreAiRequestRequest;
use App\Models\AiProduct;
use App\Models\AiRequest;
use App\Models\AiRequestDraft;
use App\Models\Area;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Pedir un desarrollo o una mejora" (`Ai/Requests/Create`,
 * RequestsCreateProps), its autosaved draft, `ai.requests.store` and
 * "Recibimos tu solicitud" (`Ai/Requests/Submitted`).
 *
 * The create page answers the partial reload `only: ['similar']` with
 * `q`: similar open requests of the requester's company (DuplicateFinder),
 * from 20 characters on.
 */
class RequestWizardController extends Controller
{
    /** Systems catalog of step 5; the requester's apps are appended. */
    public const SYSTEMS = ['SAP Business One', 'Excel', 'Correo'];

    private const SIMILAR_MIN = 20;

    private const SIMILAR_LIMIT = 5;

    /** Draft fields (`RequestDraftPayload`); anything else is dropped. */
    private const DRAFT_FIELDS = [
        'type', 'product_slug', 'problem', 'plus_one_key', 'hours_per_week', 'people', 'expected_result',
        'success_criteria', 'systems', 'systems_notes', 'urgency', 'deadline', 'deadline_reason', 'area_id',
    ];

    public function __construct(
        private readonly ProductResolver $products,
        private readonly DuplicateFinder $duplicates,
        private readonly Roadmap $roadmap,
    ) {}

    public function create(Request $http): Response
    {
        /** @var User $user */
        $user = $http->user();
        $apps = $this->apps($user);
        $areas = $this->areas($user);
        $draft = AiRequestDraft::query()->where('user_id', $user->id)->first();
        $preselected = $this->products->forUser((string) $http->query('producto', ''), $user);

        $props = [
            'product' => $preselected !== null ? RequestPresenter::productRef($preselected) : null,
            'types' => fn () => $this->types(),
            'apps' => fn () => $apps->map(fn (AiProduct $product) => RequestPresenter::productRef($product))->values()->all(),
            'systems_options' => fn () => [...self::SYSTEMS, ...$apps->pluck('name')->all()],
            'area_suggestion' => fn () => $this->areaSuggestion($user, $areas),
            'areas' => fn () => $areas->map(fn (Area $area) => ['id' => $area->id, 'name' => $area->name])->values()->all(),
            'max_attachment_mb' => intdiv((int) config('gestor.ai.attachments.max_kb'), 1024),
            'similar' => fn () => $this->similar($user, (string) $http->query('q', '')),
            'today' => now('America/Bogota')->toDateString(),
            'draft' => fn () => $draft?->data,
            'draft_saved_at' => fn () => $draft?->saved_at->toIso8601String(),
        ];

        if ($draft !== null) {
            $props['initial_step'] = $this->resumeStep($draft->data);
        }

        return Inertia::render('Ai/Requests/Create', $props);
    }

    /**
     * Autosave ("Se guarda solo"): the wizard fields without files.
     */
    public function updateDraft(Request $http): RedirectResponse
    {
        $data = [];

        foreach (self::DRAFT_FIELDS as $field) {
            $value = $http->input($field);
            $data[$field] = is_string($value) ? mb_substr($value, 0, StoreAiRequestRequest::TEXT_MAX) : $value;
        }

        $data['systems'] = array_values(array_filter((array) ($data['systems'] ?? []), 'is_string'));

        AiRequestDraft::query()->updateOrCreate(
            ['user_id' => $http->user()->id],
            ['data' => $data, 'saved_at' => now()],
        );

        return back();
    }

    public function store(StoreAiRequestRequest $form, CreateRequest $create): RedirectResponse
    {
        /** @var User $user */
        $user = $form->user();
        $request = $create->handle($user, $form->toActionData(), $form->attachments());

        AiRequestDraft::query()->where('user_id', $user->id)->delete();

        return redirect()->route('ai.requests.submitted', $request);
    }

    public function submitted(Request $http, AiRequest $request): Response
    {
        Gate::authorize('viewOwn', $request);

        $due = $request->first_response_due_at ?? $request->created_at;

        return Inertia::render('Ai/Requests/Submitted', [
            'request' => ['key' => $request->key, 'title' => $request->title],
            'first_response_due_at' => $due->toIso8601String(),
            'telegram_linked' => (bool) $http->user()->telegramLink?->isLinked(),
        ]);
    }

    /**
     * @return list<array{value: string, title: string, description: string}>
     */
    private function types(): array
    {
        return [
            ['value' => RequestType::New->value, 'title' => 'Algo nuevo', 'description' => 'Una app, un reporte o una automatización que no existe.'],
            ['value' => RequestType::Improvement->value, 'title' => 'Mejorar una app que ya usamos', 'description' => 'Agregar o cambiar algo en una app que ya funciona.'],
            ['value' => RequestType::Bug->value, 'title' => 'Algo no funciona en una app', 'description' => 'Un error, algo lento o un dato que sale mal.'],
            ['value' => RequestType::Idea->value, 'title' => 'Tengo una idea o una pregunta', 'description' => 'No sé si se puede. Quiero que TI lo mire.'],
        ];
    }

    /**
     * Products serving the requester's company, by name.
     *
     * @return Collection<int, AiProduct>
     */
    private function apps(User $user): Collection
    {
        if ($user->company_id === null) {
            return collect();
        }

        return AiProduct::query()
            ->whereHas('companies', fn ($query) => $query->whereKey($user->company_id))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Area>
     */
    private function areas(User $user): Collection
    {
        if ($user->company_id === null) {
            return collect();
        }

        return Area::query()
            ->withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->orderBy('name')
            ->get(['id', 'name', 'company_id']);
    }

    /**
     * The area of the person's employee record, else the first area of
     * their company.
     *
     * @param  Collection<int, Area>  $areas
     * @return array{id: int, name: string}
     */
    private function areaSuggestion(User $user, Collection $areas): array
    {
        $area = $areas->firstWhere('id', $user->employee?->area_id) ?? $areas->first();

        return RequestPresenter::area($area);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function similar(User $user, string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::SIMILAR_MIN || $user->company_id === null) {
            return [];
        }

        $found = $this->duplicates->similar($query, (int) $user->company_id, self::SIMILAR_LIMIT);
        $found->load(RequestPresenter::RELATIONS);
        $ranks = $this->roadmap->ranks($found);

        return $found->map(fn (AiRequest $request) => RequestPresenter::summary($request, $ranks[$request->id] ?? null))->values()->all();
    }

    /**
     * First step whose required answers are still missing (the wizard
     * re-checks every step on the client).
     *
     * @param  array<string, mixed>  $draft
     */
    private function resumeStep(array $draft): int
    {
        $blank = fn (string $key) => blank($draft[$key] ?? null);
        $type = $draft['type'] ?? null;

        return match (true) {
            $blank('type') || (in_array($type, ['improvement', 'bug'], true) && $blank('product_slug')) => 1,
            mb_strlen(trim((string) ($draft['problem'] ?? ''))) < self::SIMILAR_MIN => 2,
            $type !== 'idea' && ($blank('hours_per_week') || $blank('people')) => 3,
            $blank('expected_result') => 4,
            $blank('urgency') => 6,
            default => 7,
        };
    }
}
