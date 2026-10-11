<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\AnswerRequirement;
use App\Actions\Ai\CreateRequirement;
use App\Actions\Ai\ReassignRequirement;
use App\Actions\Ai\RemindRequirement;
use App\Domain\Ai\InvalidTransition;
use App\Domain\Ai\Week;
use App\Http\Controllers\Controller;
use App\Models\AiProject;
use App\Models\AiProjectMember;
use App\Models\AiRequirement;
use App\Models\AiTask;
use App\Models\Area;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Support\Ai\Presenters\ProjectPresenter;
use App\Support\Ai\Presenters\RequirementPresenter;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Requirements to users (PROMPT 6.4.3, types/pages/ai-projects.ts):
 *
 * - `Ai/Projects/Requirements` (`ai.projects.requirements`, `ai.manage`):
 *   open ones by due date, then the answered ones (newest answer first);
 *   `scope=all` adds the other projects of the viewer (team or lead).
 * - `ai.requirements.store` / `ai.requirements.remind` (`ai.manage`).
 * - `Ai/Requirements/Respond` (`ai.requirements.respond`), `answer`,
 *   `reassign`: the recipient (any company) or IT (AiRequirementPolicy).
 * - `ai.requirements.show` (links of Inicio and the notices): the
 *   recipient goes to the respond page, IT to the project's requirements.
 */
class RequirementController extends Controller
{
    private const ANSWER_MAX = 5000;

    /** Upload limit shown on the respond page (MB). */
    private const MAX_UPLOAD_MB = 10;

    public function __construct(private readonly ProjectPresenter $projects) {}

    public function index(Request $http, AiProject $project, Settings $settings): Response
    {
        $user = $this->user($http);
        Gate::authorize('manage', $project);

        $scope = $http->query('scope') === 'all' ? 'all' : 'project';
        $mine = $this->myProjects($user, $project);
        $projectIds = $scope === 'all' ? $mine->pluck('id')->all() : [$project->id];

        $requirements = AiRequirement::query()
            ->whereIn('ai_project_id', $projectIds)
            ->with(RequirementPresenter::RELATIONS)
            ->get();
        $open = $requirements->where('status', AiRequirement::PENDING)->sortBy(fn (AiRequirement $item) => [$item->due_date->toDateString(), $item->id]);
        $answered = $requirements->where('status', AiRequirement::ANSWERED)->sortByDesc(fn (AiRequirement $item) => $item->answered_at?->getTimestamp() ?? 0);

        return Inertia::render('Ai/Projects/Requirements', [
            'project' => $this->projects->header($project),
            'requirements' => $open->concat($answered)->map(fn (AiRequirement $item) => RequirementPresenter::requirement($item))->values()->all(),
            'scope' => $scope,
            'my_projects' => $mine->map(fn (AiProject $item) => [
                'key' => $item->key,
                'name' => $item->name,
                'tasks' => $item->tasks()
                    ->whereHas('column', fn ($query) => $query->where('is_done', false))
                    ->orderBy('number')
                    ->get(['key', 'title'])
                    ->map(fn (AiTask $task) => ['key' => $task->key, 'title' => $task->title])
                    ->values()
                    ->all(),
            ])->values()->all(),
            'recipients' => RequirementPresenter::options($this->employees()),
            'reminder_every_days' => max(1, (int) $settings->get('ai.requirements.reminder_every_days', 2)),
            'today' => Week::today()->toDateString(),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $http, AiProject $project, CreateRequirement $create): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $data = $http->validate([
            'what' => ['required', 'string', 'max:'.self::ANSWER_MAX],
            'recipient_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.Week::today()->toDateString()],
            'blocks_task' => ['nullable', 'string', Rule::exists('ai_tasks', 'key')->where('ai_project_id', $project->id)],
        ], [
            'what.required' => 'Cuenta qué necesitas.',
            'recipient_id.exists' => 'Esa persona no está activa.',
            'due_date.required' => 'Elige para cuándo lo necesitas.',
            'due_date.after_or_equal' => 'La fecha no puede ser anterior a hoy.',
            'blocks_task.exists' => 'Esa tarea no es de este proyecto.',
        ]);

        $recipient = isset($data['recipient_id']) ? User::query()->find($data['recipient_id']) : null;
        $blocks = isset($data['blocks_task']) ? $project->tasks()->where('key', $data['blocks_task'])->first() : null;

        $create->handle($project, $this->user($http), $data['what'], $recipient, CarbonImmutable::parse($data['due_date']), $blocks);

        return back()->with('success', $recipient !== null ? "Requerimiento enviado a {$recipient->name}." : 'Requerimiento guardado.');
    }

    public function remind(AiRequirement $requirement, RemindRequirement $remind): RedirectResponse
    {
        Gate::authorize('manage', $requirement);

        try {
            $remind->handle($requirement);
        } catch (InvalidTransition $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Recordatorio enviado a {$requirement->recipient_label}.");
    }

    public function show(Request $http, AiRequirement $requirement): RedirectResponse
    {
        $user = $this->user($http);
        $policy = Gate::forUser($user);

        if ($policy->allows('isRecipient', $requirement)) {
            return redirect()->route('ai.requirements.respond', $requirement->id);
        }

        if ($policy->allows('manage', $requirement)) {
            return redirect()->route('ai.projects.requirements', (string) $requirement->project?->key);
        }

        abort(403);
    }

    public function respond(Request $http, AiRequirement $requirement): Response
    {
        $user = $this->user($http);
        Gate::authorize('respond', $requirement);

        $recipient = $requirement->recipient ?? $user;
        $suggested = $this->suggested($requirement, $user);
        $candidates = $this->employees($requirement->company_id)
            ->reject(fn (Employee $employee) => (int) $employee->user_id === $recipient->id);
        $candidates = $candidates->sortBy(fn (Employee $employee) => (int) $employee->user_id === $suggested ? 0 : 1)->values();

        return Inertia::render('Ai/Requirements/Respond', [
            'requirement' => RequirementPresenter::forRecipient($requirement),
            'reassign_candidates' => RequirementPresenter::options($candidates),
            'suggested_user_id' => $suggested,
            'max_upload_mb' => self::MAX_UPLOAD_MB,
            'today' => Week::today()->toDateString(),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function answer(Request $http, AiRequirement $requirement, AnswerRequirement $answer): RedirectResponse
    {
        Gate::authorize('respond', $requirement);
        $config = (array) config('gestor.ai.attachments');
        $data = $http->validate([
            'answer' => ['required', 'string', 'max:'.self::ANSWER_MAX],
            'attachment' => ['nullable', 'file', 'max:'.(self::MAX_UPLOAD_MB * 1024), 'mimetypes:'.implode(',', (array) $config['mimetypes'])],
        ], [
            'answer.required' => 'Escribe tu respuesta.',
            'attachment.max' => 'El archivo puede pesar hasta '.self::MAX_UPLOAD_MB.' MB.',
            'attachment.mimetypes' => 'Solo imágenes, PDF, Excel o texto.',
        ]);

        try {
            $answer->handle($requirement, $this->user($http), $data['answer'], $http->file('attachment'));
        } catch (InvalidTransition $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', '¡Gracias! TI ya tiene tu respuesta.');
    }

    public function reassign(Request $http, AiRequirement $requirement, ReassignRequirement $reassign): RedirectResponse
    {
        $user = $this->user($http);
        Gate::authorize('respond', $requirement);
        $data = $http->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['user_id.required' => 'Elige a quién se lo pasas.']);

        $to = User::query()->findOrFail($data['user_id']);

        try {
            $reassign->handle($requirement, $user, $to, $data['note'] ?? null);
        } catch (InvalidTransition $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($user->can('manage', $requirement)) {
            return back()->with('success', "Ahora lo tiene {$to->name}.");
        }

        return redirect()->route('home')->with('success', "Se lo pasaste a {$to->name}. Gracias.");
    }

    /**
     * The project plus the other projects the viewer leads or works on.
     *
     * @return Collection<int, AiProject>
     */
    private function myProjects(User $user, AiProject $current): Collection
    {
        $mine = AiProject::query()
            ->where(fn ($query) => $query
                ->where('lead_id', $user->id)
                ->orWhereIn('id', AiProjectMember::query()->where('user_id', $user->id)->select('ai_project_id')))
            ->whereKeyNot($current->id)
            ->orderByDesc('id')
            ->get();

        return (new Collection([$current]))->concat($mine)->values();
    }

    /**
     * Employees with an active user and an area (company scope applies
     * unless a company is given), by area then name.
     *
     * @return \Illuminate\Support\Collection<int, Employee>
     */
    private function employees(?int $companyId = null): \Illuminate\Support\Collection
    {
        return Employee::query()
            ->when($companyId !== null, fn ($query) => $query->withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId))
            ->whereNotNull('user_id')
            ->whereNotNull('area_id')
            ->whereHas('user', fn ($query) => $query->where('active', true))
            ->with(['user', 'area' => fn ($query) => $query->withoutGlobalScopes()])
            ->get()
            ->sortBy(fn (Employee $employee) => [mb_strtolower((string) $employee->area?->name), mb_strtolower($employee->name)])
            ->values();
    }

    /**
     * Person IT thinks can answer when the viewer cannot: the approver of
     * the requirement's area, unless that is the recipient.
     */
    private function suggested(AiRequirement $requirement, User $viewer): ?int
    {
        $approver = $requirement->area_id !== null
            ? Area::query()->withoutGlobalScopes()->whereKey($requirement->area_id)->value('approver_user_id')
            : null;
        $recipientId = $requirement->recipient_id ?? $viewer->id;

        return $approver !== null && (int) $approver !== (int) $recipientId ? (int) $approver : null;
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
