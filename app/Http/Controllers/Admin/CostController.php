<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ai\TriagePeople;
use App\Domain\Ai\Week;
use App\Http\Controllers\Controller;
use App\Models\AiCostRate;
use App\Models\Area;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\RoleMatrix;
use App\Support\Settings;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración › Costos y aprobaciones (`Admin/Costs/Index`,
 * types/pages/admin-costs.ts, PROMPT 6.4.11):
 *
 * - Page: holders of `ai.costs.view`, `ai.manage` or `admin.access`;
 *   `rates` and `area_values` are null without `ai.costs.view` (the page
 *   renders NoPermission).
 * - Rates (`ai.costs.manage`): one row per IT person (`ai.manage`) and per
 *   person with a rate; current rate in force today with its date, earlier
 *   values newest first. People without a rate yet come with rate 0 (the
 *   design type has no empty rate; flagged). A change is a new
 *   `ai_cost_rates` row from its date (same date = replaced): hours
 *   already logged keep the rate of their own date.
 * - Area hour values (`ai.costs.manage`): `areas.hour_value` of the areas in
 *   the company context.
 * - Approvers (`ai.manage`): holders of `ai.projects.approve`; adding or
 *   removing one assigns or removes the role "Aprobador de proyectos"
 *   (activity log `approver_added|approver_removed`); the last one stays.
 *   Candidates: active IT or cost viewers who are not approvers.
 * - Settings (`ai.manage`): `ai.approval.require_other_if_evaluator` and
 *   `ai.reapproval_threshold` (percent 1–100 → 0.01–1).
 */
class CostController extends Controller
{
    public function __construct(
        private readonly TriagePeople $people,
        private readonly Settings $settings,
    ) {}

    public function index(Request $http): Response
    {
        $user = $this->user($http);
        abort_unless($user->canAny(['ai.costs.view', 'ai.manage', 'admin.access']), 403);
        $withCosts = $user->can('ai.costs.view');

        return Inertia::render('Admin/Costs/Index', [
            'rates' => $withCosts ? $this->rates() : null,
            'area_values' => $withCosts ? $this->areaValues() : null,
            'approvers' => $this->people->approvers()->map(fn (User $approver) => [
                'person' => RequestPresenter::person($approver),
                'detail' => self::detail($approver),
            ])->values()->all(),
            'approver_candidates' => $this->candidates()->map(fn (User $candidate) => RequestPresenter::person($candidate))->values()->all(),
            'settings' => [
                'require_other_approver' => (bool) $this->settings->get('ai.approval.require_other_if_evaluator', false),
                'reapproval_threshold_percent' => (int) round((float) $this->settings->get('ai.reapproval_threshold', 0.2) * 100),
            ],
            'today' => Week::today()->toDateString(),
        ]);
    }

    public function updateRate(Request $http, int $user): RedirectResponse
    {
        $person = $this->ratePeople()->firstWhere('id', $user);
        abort_if($person === null, 404);

        $data = $http->validate([
            'rate' => ['required', 'integer', 'min:1', 'max:100000000'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ], [
            'rate.*' => 'Pon el costo por hora en pesos.',
            'effective_from.*' => 'Elige desde cuándo rige.',
        ]);

        DB::transaction(function () use ($person, $data, $http): void {
            $row = AiCostRate::query()
                ->where('user_id', $person->id)
                ->whereDate('effective_from', $data['effective_from'])
                ->lockForUpdate()
                ->first() ?? new AiCostRate(['user_id' => $person->id, 'effective_from' => $data['effective_from']]);

            $row->fill(['rate' => (int) $data['rate'], 'changed_by_id' => $this->user($http)->id])->save();
        });

        return back()->with('success', "Tarifa de {$person->name} guardada.");
    }

    public function updateAreaValues(Request $http): RedirectResponse
    {
        $areas = $this->areas()->keyBy('id');

        $data = $http->validate([
            'values' => ['present', 'array'],
            'values.*.area_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($areas): void {
                if (! $areas->has((int) $value)) {
                    $fail('Esa área no está disponible.');
                }
            }],
            'values.*.value' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ], [
            'values.*.value.*' => 'Pon el valor de la hora en pesos o déjalo vacío.',
        ]);

        DB::transaction(function () use ($data, $areas): void {
            foreach ($data['values'] as $row) {
                /** @var Area $area */
                $area = $areas[(int) $row['area_id']];
                $area->forceFill(['hour_value' => $row['value'] === null ? null : (int) $row['value']])->save();
            }
        });

        return back()->with('success', 'Valores de la hora guardados.');
    }

    public function storeApprover(Request $http): RedirectResponse
    {
        $candidates = $this->candidates();
        $data = $http->validate(['user_id' => ['required', 'integer']], ['user_id.*' => 'Elige a quién agregar.']);
        $target = $candidates->firstWhere('id', (int) $data['user_id']);

        if ($target === null) {
            throw ValidationException::withMessages(['user_id' => 'Solo personas de TI o de la gerencia pueden aprobar proyectos.']);
        }

        $target->assignRole(RoleMatrix::projectApprovalRole());

        activity('ai')
            ->performedOn($target)
            ->causedBy($this->user($http))
            ->event('approver_added')
            ->log("Agregó a {$target->name} como aprobador de proyectos");

        return back()->with('success', "{$target->name} ahora aprueba proyectos.");
    }

    public function destroyApprover(Request $http, int $user): RedirectResponse
    {
        $approvers = $this->people->approvers();
        $target = $approvers->firstWhere('id', $user);
        abort_if($target === null, 404);

        if ($approvers->count() <= 1) {
            throw ValidationException::withMessages(['user' => 'Debe quedar al menos un aprobador de proyectos.']);
        }

        $target->removeRole(RoleMatrix::projectApprovalRole());

        activity('ai')
            ->performedOn($target)
            ->causedBy($this->user($http))
            ->event('approver_removed')
            ->log("Quitó a {$target->name} de los aprobadores de proyectos");

        return back()->with('success', "{$target->name} ya no aprueba proyectos.");
    }

    public function updateSettings(Request $http): RedirectResponse
    {
        $data = $http->validate([
            'require_other_approver' => ['required', 'boolean'],
            'reapproval_threshold_percent' => ['required', 'integer', 'between:1,100'],
        ], [
            'reapproval_threshold_percent.*' => 'Usa un porcentaje entre 1 y 100.',
        ]);

        $this->settings->set('ai.approval.require_other_if_evaluator', (bool) $data['require_other_approver']);
        $this->settings->set('ai.reapproval_threshold', round((int) $data['reapproval_threshold_percent'] / 100, 2));

        activity('ai')
            ->causedBy($this->user($http))
            ->event('cost_settings_updated')
            ->withProperties([
                'require_other_approver' => (bool) $data['require_other_approver'],
                'reapproval_threshold_percent' => (int) $data['reapproval_threshold_percent'],
            ])
            ->log('Cambió la configuración de costos y aprobaciones');

        return back()->with('success', 'Cambios guardados.');
    }

    /**
     * IT people and anyone with a rate, active, by name.
     *
     * @return EloquentCollection<int, User>
     */
    private function ratePeople(): EloquentCollection
    {
        $ids = [...$this->people->team()->modelKeys(), ...AiCostRate::query()->distinct()->pluck('user_id')->all()];

        return User::query()
            ->whereIn('id', array_unique(array_map('intval', $ids)))
            ->where('active', true)
            ->with('employee')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rates(): array
    {
        $people = $this->ratePeople();
        $today = Week::today()->toDateString();
        $rows = AiCostRate::query()
            ->whereIn('user_id', $people->modelKeys())
            ->with('changedBy')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id');

        return $people->map(function (User $person) use ($rows, $today): array {
            /** @var Collection<int, AiCostRate> $history */
            $history = $rows->get($person->id, collect());
            $current = $history->first(fn (AiCostRate $row) => $row->effective_from->toDateString() <= $today)
                ?? $history->last();

            return [
                'person' => RequestPresenter::person($person),
                'rate' => (int) ($current?->rate ?? 0),
                'effective_from' => $current?->effective_from->toDateString() ?? $today,
                'history' => $history
                    ->reject(fn (AiCostRate $row) => $current !== null && $row->is($current))
                    ->map(fn (AiCostRate $row) => [
                        'rate' => $row->rate,
                        'effective_from' => $row->effective_from->toDateString(),
                        'changed_by' => $row->changedBy?->name,
                    ])
                    ->values()
                    ->all(),
            ];
        })->values()->all();
    }

    /**
     * Active areas in the company context, by company and name.
     *
     * @return EloquentCollection<int, Area>
     */
    private function areas(): EloquentCollection
    {
        return Area::query()
            ->with('company')
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (Area $area) => [(string) $area->company?->name, $area->name, $area->id])
            ->values();
    }

    /**
     * @return list<array{area: array{id: int, name: string, company_id: int}, value: ?int}>
     */
    private function areaValues(): array
    {
        return $this->areas()->map(fn (Area $area) => [
            'area' => ['id' => $area->id, 'name' => $area->name, 'company_id' => (int) $area->company_id],
            'value' => $area->hour_value,
        ])->values()->all();
    }

    /**
     * Active IT (`ai.manage`) or cost viewers (`ai.costs.view`) who do not
     * approve yet, by name.
     *
     * @return EloquentCollection<int, User>
     */
    private function candidates(): EloquentCollection
    {
        $approvers = $this->people->approvers()->modelKeys();

        return User::query()
            ->where(fn ($query) => $query->permission('ai.manage')->orWhere(fn ($inner) => $inner->permission('ai.costs.view')))
            ->where('active', true)
            ->whereNotIn('id', $approvers)
            ->with('employee')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * "Líder IA · Segurex": job title (else another role), area, company.
     */
    private static function detail(User $user): string
    {
        $role = $user->getRoleNames()->first(fn (string $name) => $name !== RoleMatrix::projectApprovalRole());
        $parts = [
            $user->employee?->job_title ?: $role,
            $user->employee?->area?->name,
            $user->company?->name,
        ];

        return implode(' · ', array_values(array_filter($parts, fn ($part) => filled($part))));
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
