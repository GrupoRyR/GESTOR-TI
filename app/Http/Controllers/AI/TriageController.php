<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\AskForInformation;
use App\Actions\Ai\CommentOnRequest;
use App\Actions\Ai\EvaluateRequest;
use App\Actions\Ai\MergeRequests;
use App\Actions\Ai\RemindPending;
use App\Actions\Ai\SendToApproval;
use App\Actions\Ai\TransitionRequest;
use App\Domain\Ai\BusinessDays;
use App\Domain\Ai\Money;
use App\Domain\Ai\TriagePeople;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\EffortSize;
use App\Enums\Ai\RejectReason;
use App\Enums\Ai\RequestStatus;
use App\Enums\Ai\RiskLevel;
use App\Enums\Ai\Urgency;
use App\Http\Controllers\AI\Concerns\RedirectsTriageErrors;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * IT actions on one request (types/pages/ai-triage.ts dialogs). Routes
 * check `ai.manage`; each action re-checks `evaluate`/`transition` on the
 * request (company access). Every answer goes back with a flash message.
 *
 * - `ai.requests.evaluation.update` PUT  EvaluationPayload (first response)
 * - `ai.requests.notes.store`       POST `{ body }` internal note
 * - `ai.requests.reject`            POST RejectPayload
 * - `ai.requests.ask`               POST `{ question }` → Necesita información
 * - `ai.requests.merge`             POST `{ into }` → MergeRequests
 * - `ai.requests.approval.store`    POST SendToApprovalPayload
 * - `ai.requests.backlog`           POST `{ note? }` → En backlog (not in the design contract yet)
 * - `ai.requests.remind`            POST — question or validation waiting on the requester
 */
class TriageController extends Controller
{
    use RedirectsTriageErrors;

    private const TEXT_MAX = 2000;

    public function evaluate(Request $http, AiRequest $request, EvaluateRequest $evaluate): RedirectResponse
    {
        $data = $http->validate([
            'size' => ['nullable', Rule::enum(EffortSize::class)],
            'urgency' => ['nullable', Rule::enum(Urgency::class)],
            'risk' => ['required', Rule::enum(RiskLevel::class)],
            'priority_final' => ['nullable', 'integer', 'between:0,100'],
        ], [
            'size.*' => 'Escoge una talla (S, M, L o XL).',
            'urgency.*' => 'Escoge la urgencia.',
            'risk.*' => 'Escoge el riesgo que reduce.',
            'priority_final.*' => 'La prioridad va de 0 a 100.',
        ]);

        return $this->attempt(function () use ($http, $request, $evaluate, $data): string {
            $evaluate->handle(
                $request,
                $this->user($http),
                isset($data['size']) ? EffortSize::from($data['size']) : null,
                isset($data['urgency']) ? Urgency::from($data['urgency']) : null,
                RiskLevel::from($data['risk']),
                isset($data['priority_final']) ? (int) $data['priority_final'] : null,
                Money::impactPerMonth($request),
            );

            return "Evaluación de {$request->key} guardada.";
        });
    }

    public function note(Request $http, AiRequest $request, CommentOnRequest $comments): RedirectResponse
    {
        $data = $http->validate(['body' => ['required', 'string', 'max:5000']], ['body.*' => 'Escribe la nota (hasta 5.000 caracteres).']);

        $comments->handle($request, $this->user($http), $data['body'], CommentVisibility::Internal);

        return back()->with('success', 'Nota interna guardada.');
    }

    public function reject(Request $http, AiRequest $request, TransitionRequest $transition): RedirectResponse
    {
        $data = $http->validate([
            'reason' => ['required', Rule::enum(RejectReason::class)],
            'message' => ['required', 'string', 'max:'.self::TEXT_MAX],
        ], [
            'reason.*' => 'Escoge el motivo del rechazo.',
            'message.*' => 'Escribe el mensaje para quien la pidió.',
        ]);

        return $this->attempt(function () use ($http, $request, $transition, $data): string {
            $transition->handle($request, RequestStatus::Rejected, $this->user($http), $data['message'], RejectReason::from($data['reason']));

            return "{$request->key} quedó rechazada.";
        });
    }

    public function ask(Request $http, AiRequest $request, AskForInformation $ask): RedirectResponse
    {
        $data = $http->validate(
            ['question' => ['required', 'string', 'max:'.AskForInformation::QUESTION_MAX]],
            ['question.*' => 'Escribe la pregunta (hasta 2.000 caracteres).'],
        );

        return $this->attempt(function () use ($http, $request, $ask, $data): string {
            $ask->handle($request, $this->user($http), $data['question']);

            return 'Pregunta enviada a '.$request->requester?->name.'.';
        });
    }

    public function merge(Request $http, AiRequest $request, MergeRequests $merge): RedirectResponse
    {
        $data = $http->validate(['into' => ['required', 'string', 'max:20']], ['into.*' => 'Elige la solicitud que se queda.']);

        $into = AiRequest::query()->where('key', $data['into'])->first();

        if ($into === null) {
            return back()->withErrors(['into' => 'Esa solicitud no existe o no es de la empresa en vista.']);
        }

        return $this->attempt(function () use ($http, $request, $into, $merge): string {
            $merge->handle($request, $into, $this->user($http));

            return "{$request->key} se unió con {$into->key}.";
        });
    }

    public function sendToApproval(Request $http, AiRequest $request, SendToApproval $send, TriagePeople $people): RedirectResponse
    {
        $team = $people->team()->modelKeys();
        $approvers = $people->approvers()->modelKeys();
        $today = now(BusinessDays::TIMEZONE)->toDateString();

        $data = $http->validate([
            'hours' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, Closure $fail) use ($team): void {
                $rows = is_array($value) ? $value : [];
                $total = 0.0;

                foreach ($rows as $row) {
                    if (! is_array($row) || ! in_array((int) ($row['person_id'] ?? 0), $team, true)) {
                        $fail('Solo las personas de TI van en el estimado.');

                        return;
                    }

                    $total += max(0, (float) ($row['hours'] ?? 0));
                }

                if ($total <= 0) {
                    $fail('Escribe las horas de al menos una persona.');
                }
            }],
            'hours.*.person_id' => ['required', 'integer'],
            'hours.*.hours' => ['required', 'numeric', 'min:0', 'max:10000'],
            'external_costs' => ['required', 'integer', 'min:0'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'approver_ids' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, Closure $fail) use ($approvers): void {
                if (! is_array($value) || array_diff(array_map('intval', $value), $approvers) !== []) {
                    $fail('Elige aprobadores de proyectos (rol Aprobador de proyectos).');
                }
            }],
            'approver_ids.*' => ['integer'],
            'require_all' => ['boolean'],
        ], [
            'hours.required' => 'Escribe las horas de al menos una persona.',
            'external_costs.*' => 'Escribe los costos externos en pesos (0 si no hay).',
            'due_date.*' => 'Elige una fecha estimada de entrega desde hoy.',
            'approver_ids.required' => 'Elige al menos un aprobador.',
        ]);

        return $this->attempt(function () use ($http, $request, $send, $data): string {
            $send->handle(
                $request,
                $this->user($http),
                array_values($data['hours']),
                (int) $data['external_costs'],
                $data['due_date'],
                array_values(array_map('intval', $data['approver_ids'])),
                (bool) ($data['require_all'] ?? false),
            );

            return "{$request->key} quedó esperando aprobación.";
        });
    }

    public function backlog(Request $http, AiRequest $request, TransitionRequest $transition): RedirectResponse
    {
        $data = $http->validate(['note' => ['nullable', 'string', 'max:'.self::TEXT_MAX]]);

        return $this->attempt(function () use ($http, $request, $transition, $data): string {
            $transition->handle($request, RequestStatus::Backlog, $this->user($http), $data['note'] ?? null);

            return "{$request->key} quedó en el backlog.";
        });
    }

    public function remind(Request $http, AiRequest $request, RemindPending $remind): RedirectResponse
    {
        return $this->attemptQuietly(function () use ($http, $request, $remind): string {
            $remind->requester($request, $this->user($http));

            return 'Recordatorio enviado a '.$request->requester?->name.'.';
        });
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
