<?php

namespace App\Http\Controllers\AI;

use App\Domain\Ai\Backlog;
use App\Domain\Ai\WipLimit;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\Presenters\TriagePresenter;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Backlog priorizado" (`Ai/Backlog/Index`, BacklogIndexProps), its manual
 * order (`ai.backlog.reorder` PUT `{ order }`) and "Volver al orden
 * sugerido" (`ai.backlog.manual` DELETE). Rules in App\Domain\Ai\Backlog;
 * `ai.manage` is checked by the routes.
 */
class BacklogController extends Controller
{
    public function __construct(
        private readonly Backlog $backlog,
        private readonly WipLimit $wip,
        private readonly CurrentCompany $companies,
    ) {}

    public function index(Request $http): Response
    {
        $areaId = $this->intQuery($http, 'area_id');
        $companyId = $this->companies->isAll() ? $this->intQuery($http, 'company_id') : null;
        $items = $this->backlog->items($areaId, $companyId);
        $items->load(RequestPresenter::RELATIONS);

        return Inertia::render('Ai/Backlog/Index', [
            'items' => $items->values()->map(fn (AiRequest $request, int $i) => RequestPresenter::summary($request, $i + 1))->all(),
            'manual_order' => $this->manualOrder(),
            'wip' => $this->wip->snapshot(),
            'filters' => ['area_id' => $areaId, 'company_id' => $companyId],
            'areas' => Area::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get()
                ->map(fn (Area $area) => TriagePresenter::areaOption($area))->values()->all(),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function reorder(Request $http): RedirectResponse
    {
        $data = $http->validate([
            'order' => ['required', 'array', 'min:1', 'max:500'],
            'order.*' => ['string', 'max:20'],
        ]);

        /** @var User $user */
        $user = $http->user();
        $this->backlog->reorder(array_values($data['order']), $user);

        return back()->with('success', 'Orden del backlog guardado.');
    }

    public function reset(Request $http): RedirectResponse
    {
        /** @var User $user */
        $user = $http->user();
        $this->backlog->reset($user);

        return back()->with('success', 'El backlog volvió al orden sugerido.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manualOrder(): ?array
    {
        $order = $this->backlog->manualOrder();
        $by = $order === null ? null : User::query()->with('employee')->find($order['by_id']);

        if ($order === null || $by === null) {
            return null;
        }

        return [
            'by' => RequestPresenter::person($by),
            'at' => $order['at'],
            'moved_key' => $order['moved_key'],
            'above_key' => $order['above_key'],
            'note' => $order['note'],
        ];
    }

    private function intQuery(Request $http, string $key): ?int
    {
        $value = $http->query($key);

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
