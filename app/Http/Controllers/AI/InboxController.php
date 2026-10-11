<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\AskForInformation;
use App\Domain\Ai\DuplicateFinder;
use App\Domain\Ai\FirstResponse;
use App\Domain\Ai\Inbox;
use App\Domain\Ai\InvalidTransition;
use App\Domain\Ai\Roadmap;
use App\Domain\Ai\TriagePeople;
use App\Enums\Ai\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\Presenters\TriagePresenter;
use App\Support\Paginated;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Bandeja de solicitudes" (`Ai/Inbox/Index`, InboxIndexProps) and its bulk
 * bar (`ai.requests.bulk.ask`, `ai.requests.bulk.assign`). `ai.manage` is
 * checked by the routes. `ai.requests.index` (Inicio "Ver todas") lands
 * here with the matching chip.
 */
class InboxController extends Controller
{
    private const PER_PAGE = 20;

    private const BULK_MAX = 50;

    public function __construct(
        private readonly Inbox $inbox,
        private readonly Roadmap $roadmap,
        private readonly TriagePresenter $triage,
        private readonly CurrentCompany $companies,
    ) {}

    public function index(Request $http, FirstResponse $firstResponse): Response
    {
        /** @var User $user */
        $user = $http->user();
        $quick = in_array($http->query('quick'), Inbox::QUICK, true) ? (string) $http->query('quick') : 'all';
        $areaId = $this->intQuery($http, 'area_id');
        $companyId = $this->companies->isAll() ? $this->intQuery($http, 'company_id') : null;

        $page = Inbox::order(Inbox::quick($this->inbox->query($areaId, $companyId), $quick))
            ->with(RequestPresenter::RELATIONS)
            ->paginate(self::PER_PAGE)
            ->withQueryString();
        $ranks = $this->roadmap->ranks($page->items());

        return Inertia::render('Ai/Inbox/Index', [
            ...$this->triage->actionData($user),
            'requests' => Paginated::from($page, fn (AiRequest $request) => RequestPresenter::summary($request, $ranks[$request->id] ?? null)),
            'filters' => ['quick' => $quick, 'area_id' => $areaId, 'company_id' => $companyId],
            'counts' => $this->inbox->counts($areaId, $companyId),
            'areas' => Area::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get()
                ->map(fn (Area $area) => TriagePresenter::areaOption($area))->values()->all(),
            'first_response_business_days' => $firstResponse->businessDays(),
            'now' => now()->toIso8601String(),
            'last_answered' => $this->lastAnswered(),
            'merge_candidates' => Inertia::optional(fn () => self::mergeCandidates((string) $http->query('q', ''))),
        ]);
    }

    /**
     * Inicio "Ver todas": `{ attention?, status? }` → the inbox chip, or
     * Mi semana for tasks and requirements (B5).
     */
    public function requests(Request $http): RedirectResponse
    {
        return match (true) {
            $http->query('status') === 'pending_approval' => redirect()->route('ai.inbox.index', ['quick' => 'pending_approval']),
            $http->query('attention') === 'unanswered' => redirect()->route('ai.inbox.index', ['quick' => 'unanswered']),
            in_array($http->query('attention'), ['overdue_tasks', 'waiting_requirements'], true) => redirect()->route('ai.week.index'),
            default => redirect()->route('ai.inbox.index'),
        };
    }

    /**
     * AskInfoDialog from the bulk bar: `{ keys, question }`. Requests that
     * cannot move to "Necesita información" are reported in the error flash.
     */
    public function bulkAsk(Request $http, AskForInformation $ask): RedirectResponse
    {
        $data = $http->validate([
            'keys' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX],
            'keys.*' => ['string', 'max:20'],
            'question' => ['required', 'string', 'max:'.AskForInformation::QUESTION_MAX],
        ], ['question.*' => 'Escribe la pregunta (hasta 2.000 caracteres).']);

        $asked = 0;
        $skipped = [];

        foreach ($this->requestsFor($data['keys']) as $key => $request) {
            if ($request === null) {
                $skipped[] = $key;

                continue;
            }

            try {
                $ask->handle($request, $http->user(), $data['question']);
                $asked++;
            } catch (InvalidTransition|ValidationException|AuthorizationException) {
                $skipped[] = $key;
            }
        }

        $response = back()->with('success', $asked === 1 ? 'Pregunta enviada a 1 solicitud.' : "Pregunta enviada a {$asked} solicitudes.");

        return $skipped === [] ? $response : $response->with('error', 'No se pudo preguntar en '.implode(', ', $skipped).': su estado no lo permite.');
    }

    /**
     * "Asignar a…": `{ keys, user_id }`; the person must be of the IT team.
     */
    public function bulkAssign(Request $http, TriagePeople $people): RedirectResponse
    {
        $team = $people->team()->modelKeys();

        $data = $http->validate([
            'keys' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX],
            'keys.*' => ['string', 'max:20'],
            'user_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($team): void {
                if (! in_array((int) $value, $team, true)) {
                    $fail('Elige a alguien del equipo de TI.');
                }
            }],
        ]);

        $assigned = 0;

        foreach ($this->requestsFor($data['keys']) as $request) {
            if ($request !== null && $http->user()->can('evaluate', $request)) {
                $request->forceFill(['assignee_id' => (int) $data['user_id']])->save();
                $assigned++;
            }
        }

        return back()->with('success', $assigned === 1 ? '1 solicitud asignada.' : "{$assigned} solicitudes asignadas.");
    }

    /**
     * MergeDialog results (`only: ['merge_candidates']`, `{ q }`): an exact
     * key first, then similar open requests of the company in view.
     *
     * @return list<array<string, mixed>>
     */
    public static function mergeCandidates(string $q, ?AiRequest $exclude = null): array
    {
        $q = trim($q);

        if (mb_strlen($q) < 3) {
            return [];
        }

        $exact = preg_match('/^GIA-\d+$/i', $q) === 1
            ? AiRequest::query()->where('key', strtoupper($q))->whereNotIn('status', RequestStatus::finishedValues())->first()
            : null;

        $companyId = app(CurrentCompany::class)->id();
        $similar = app(DuplicateFinder::class)->similar($q, $companyId, 8, $exclude?->id);

        return collect([$exact])
            ->merge($similar)
            ->filter()
            ->reject(fn (AiRequest $request) => $exclude !== null && $request->is($exclude))
            ->unique('id')
            ->take(8)
            ->each(fn (AiRequest $request) => $request->loadMissing(RequestPresenter::RELATIONS))
            ->map(fn (AiRequest $request) => RequestPresenter::summary($request))
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, created_at: string, answered_at: string}|null
     */
    private function lastAnswered(): ?array
    {
        $request = AiRequest::query()->whereNotNull('first_responded_at')->latest('first_responded_at')->latest('id')->first();

        return $request === null ? null : [
            'key' => $request->key,
            'created_at' => $request->created_at->toIso8601String(),
            'answered_at' => $request->first_responded_at->toIso8601String(),
        ];
    }

    /**
     * Requests of the keys in the company context (null when not found).
     *
     * @param  list<string>  $keys
     * @return array<string, ?AiRequest>
     */
    private function requestsFor(array $keys): array
    {
        $found = AiRequest::query()->whereIn('key', $keys)->get()->keyBy('key');
        $result = [];

        foreach (array_unique($keys) as $key) {
            $result[$key] = $found->get($key);
        }

        return $result;
    }

    private function intQuery(Request $http, string $key): ?int
    {
        $value = $http->query($key);

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
