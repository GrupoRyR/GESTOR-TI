<?php

namespace App\Http\Controllers\Assistant;

use App\Actions\Ai\Vault\VaultHolders;
use App\Domain\Assistant\CredentialDelivery;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureVaultAccess;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\EmployeeAccess;
use App\Models\User;
use App\Notifications\AssistantCredentialRevealed;
use App\Services\Credentials\EmployeeCredentialService;
use App\Support\Assistant\ChatPage;
use App\Support\Vault\AccessOrigin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Mostrar" and "Copiar" on a `credential_notice` (PROMPT 6.8.2,
 * types/pages/assistant-chat.ts). Only the owner of the conversation, with
 * `assistant.credentials` AND `credentials.view`, within
 * `gestor.assistant.credentials.reveal_minutes` of the delivery.
 *
 * - reveal: the value is read through EmployeeCredentialService::reveal()
 *   (audited, channel `assistant`), Jhon is noticed by Telegram, and the
 *   chat page is rendered once with `revealed_credential` (history
 *   encrypted, `Cache-Control: no-store`). It may be shown again within
 *   the window; each time is audited and noticed.
 * - copied: audited (`credential_copied`); the value is never sent back.
 */
class CredentialController extends Controller
{
    public const EXPIRED = 'Esta entrega venció. Pide la contraseña otra vez en el chat.';

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function __construct(
        private readonly ChatPage $page,
        private readonly EmployeeCredentialService $credentials,
        private readonly VaultHolders $holders,
    ) {}

    public function reveal(Request $http, AssistantMessage $message): Response
    {
        $user = $this->user($http);
        $conversation = $this->notice($user, $message);

        if ($message->created_at->addMinutes((int) config('gestor.assistant.credentials.reveal_minutes', 10))->isPast()) {
            return $this->toConversation($conversation)->with('error', self::EXPIRED);
        }

        $access = EmployeeAccess::query()->find((int) $message->data('access_id'));
        if ($access === null) {
            return $this->toConversation($conversation)->with('error', 'Ese acceso ya no existe.');
        }

        $value = $this->credentials->reveal($user, $access, 'assistant');
        $this->notifyReveal($http, $user, $access);

        $revealed = [
            'message_id' => $message->id,
            'value' => $value,
            'expires_in_seconds' => min(30, (int) config('gestor.assistant.credentials.reveal_seconds', 30)),
        ];
        unset($value);

        Inertia::encryptHistory();

        return $this->noStore($this->chat($http, $user, $conversation, $revealed));
    }

    public function copied(Request $http, AssistantMessage $message): Response
    {
        $user = $this->user($http);
        $conversation = $this->notice($user, $message);

        $access = EmployeeAccess::query()->find((int) $message->data('access_id'));

        activity('credentials')
            ->causedBy($user)
            ->performedOn($access ?? $message)
            ->event('credential_copied')
            ->withProperties([
                'channel' => 'assistant',
                'employee_id' => $message->data('employee_id'),
                'access_system' => $message->data('system'),
            ])
            ->log('credential_copied');

        return $this->noStore($this->chat($http, $user, $conversation, null));
    }

    /**
     * The person's own credential notice, with both credential permissions.
     */
    private function notice(User $user, AssistantMessage $message): AssistantConversation
    {
        $conversation = AssistantConversation::query()->ownedBy($user)->whereKey($message->conversation_id)->first();
        abort_if($conversation === null || $message->kind !== 'credential_notice', 404);
        abort_unless(CredentialDelivery::allowed($user), 403);

        return $conversation;
    }

    /**
     * The chat page rendered from the POST, keeping the chat URL.
     *
     * @param  array{message_id: int, value: string, expires_in_seconds: int}|null  $revealed
     */
    private function chat(Request $http, User $user, AssistantConversation $conversation, ?array $revealed): Response
    {
        $url = route('assistant.index', ['conversation' => $conversation->id], false);

        Inertia::resolveUrlUsing(fn () => $url);
        try {
            $page = $this->page->render($http, $user, $conversation, $revealed);
        } finally {
            Inertia::resolveUrlUsing(null);
        }

        return $page->toResponse($http);
    }

    private function notifyReveal(Request $http, User $user, EmployeeAccess $access): void
    {
        $holders = $this->holders->all();
        if ($holders->isEmpty()) {
            return;
        }

        $now = now()->timezone('America/Bogota');

        Notification::send($holders, new AssistantCredentialRevealed(
            $user->name,
            (string) $access->employee?->name,
            (string) $access->system?->name,
            'el chat del Gestor TI ('.AccessOrigin::describe(mb_substr((string) $http->userAgent(), 0, 255), $http->ip()).')',
            $now->day.' '.self::MONTHS[$now->month - 1].' '.$now->year.', '.$now->format('H:i'),
        ));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', EnsureVaultAccess::NO_STORE);

        return $response;
    }

    private function toConversation(AssistantConversation $conversation): RedirectResponse
    {
        return redirect()->route('assistant.index', ['conversation' => $conversation->id]);
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }
}
