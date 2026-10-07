<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\ActivityPropertiesSanitizer;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Administración › Auditoría: who changed what and when, with filters.
 *
 * Company scope (kept simple on purpose): users without
 * `companies.view_all` — and global users who picked one company in the top
 * bar selector — only see activities caused by users of that company.
 * Activities without a causer (jobs, console) are only visible in "Todas".
 * Properties are sanitized again before leaving the server
 * (`ActivityPropertiesSanitizer`).
 */
class AuditController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function index(Request $request): Response
    {
        $options = $this->options();

        $search = $request->string('search')->squish()->value() ?: null;
        $causerId = $request->integer('causer_id') ?: null;
        $logName = in_array($request->query('log_name'), $options['log_names'], true) ? $request->query('log_name') : null;
        $subjectType = in_array($request->query('subject_type'), $options['subject_types'], true) ? $request->query('subject_type') : null;
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $paginator = $this->scoped()
            ->with('causer')
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('description', 'like', '%'.$search.'%')
                ->orWhere('event', 'like', '%'.$search.'%')))
            ->when($causerId, fn (Builder $query) => $query
                ->where('causer_type', (new User)->getMorphClass())
                ->where('causer_id', $causerId))
            ->when($logName, fn (Builder $query) => $query->where('log_name', $logName))
            ->when($subjectType, fn (Builder $query) => $query->whereIn('subject_type', $this->subjectClasses($subjectType)))
            ->when($from, fn (Builder $query) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn (Builder $query) => $query->where('created_at', '<=', $to->endOfDay()))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Admin/Audit/Index', [
            'activities' => [
                'data' => collect($paginator->items())->map(fn (Activity $activity) => $this->toListArray($activity))->all(),
                'links' => $paginator->linkCollection()->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'filters' => [
                'search' => $search,
                'causer_id' => $causerId,
                'subject_type' => $subjectType,
                'log_name' => $logName,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'options' => $options,
        ]);
    }

    /**
     * Activities visible in the current company context.
     *
     * @return Builder<Activity>
     */
    private function scoped(): Builder
    {
        $query = Activity::query();

        if ($this->currentCompany->isAll()) {
            return $query;
        }

        $companyId = $this->currentCompany->id();

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('causer_type', (new User)->getMorphClass())
            ->whereIn('causer_id', User::query()->select('id')->where('company_id', $companyId));
    }

    /**
     * @return array{log_names: list<string>, subject_types: list<string>, causers: list<array{id: int, name: string}>}
     */
    private function options(): array
    {
        $logNames = $this->scoped()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name');

        $subjectTypes = $this->fullSubjectTypes()
            ->map(fn (string $type) => class_basename($type))
            ->unique()
            ->sort()
            ->values();

        $causers = User::query()
            ->whereIn('id', $this->scoped()
                ->where('causer_type', (new User)->getMorphClass())
                ->select('causer_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);

        return [
            'log_names' => $logNames->values()->all(),
            'subject_types' => $subjectTypes->all(),
            'causers' => $causers->values()->all(),
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function fullSubjectTypes(): Collection
    {
        return $this->scoped()->whereNotNull('subject_type')->distinct()->pluck('subject_type');
    }

    /**
     * Stored subject types (class names) whose short name matches.
     *
     * @return list<string>
     */
    private function subjectClasses(string $shortName): array
    {
        return $this->fullSubjectTypes()
            ->filter(fn (string $type) => class_basename($type) === $shortName)
            ->values()
            ->all();
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toListArray(Activity $activity): array
    {
        $causer = $activity->causer;

        return [
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'description' => $activity->description,
            'event' => $activity->event,
            'subject_type' => $activity->subject_type === null ? null : class_basename($activity->subject_type),
            'subject_id' => $activity->subject_id,
            'causer' => $causer === null ? null : ['id' => $causer->getKey(), 'name' => (string) ($causer->name ?? '')],
            'properties' => ActivityPropertiesSanitizer::sanitize($activity->properties?->toArray() ?? []),
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }
}
