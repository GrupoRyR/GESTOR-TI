<?php

namespace App\Telegram\Handlers;

use App\Actions\Ai\RecordApprovalDecision;
use App\Domain\Ai\ApprovalRounds;
use App\Enums\Ai\ApprovalChannel;
use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\ApprovalKind;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Telegram\Contracts\CallbackHandler;
use App\Telegram\Contracts\PendingReplyHandler;
use App\Telegram\PendingReplies;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ✅ Aprobar / ❌ Rechazar of a project approval sent by Telegram
 * (prefix `ai-approval`, config/gestor.php `telegram.callbacks`).
 *
 * Callback data: `ai-approval:{approval_id}:{a|r}:{signature}` — the
 * signature is an HMAC of the id and action with the app key (14 base64url
 * characters), so the data is opaque and stays under Telegram's 64 bytes.
 *
 * - Only the approver of that row (the user linked to the chat) may use it;
 *   anyone else gets "Esta aprobación no es tuya" and nothing changes.
 * - An approval already decided (by them or because the request left
 *   "Pendiente de aprobación") is idempotent: the message is edited to show
 *   the result and nothing is recorded again.
 * - ✅ records the decision (channel `telegram`, App\Actions\Ai\
 *   RecordApprovalDecision) and edits the original message (no buttons).
 * - ❌ needs a reason (a rejection always carries a comment): the bot asks
 *   for it and the next plain-text message of that chat within 15 minutes
 *   is the reason (`handleReply()`, App\Telegram\PendingReplies kind
 *   `approval-reason`, called by UpdateHandler before anything else); `/cancelar`
 *   drops it. The web page remains the alternative.
 */
class ApprovalCallbackHandler implements CallbackHandler, PendingReplyHandler
{
    public const PREFIX = 'ai-approval';

    public const REPLY_KIND = 'approval-reason';

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const CANCEL_COMMAND = '/cancelar';

    /** Minutes the bot waits for the rejection reason. */
    private const REASON_MINUTES = 15;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly RecordApprovalDecision $decisions,
        private readonly PendingReplies $replies,
    ) {}

    public static function data(int $approvalId, string $action): string
    {
        $code = $action === self::REJECT ? 'r' : 'a';

        return self::PREFIX.':'.$approvalId.':'.$code.':'.self::signature($approvalId, $code);
    }

    public function handle(User $user, string $payload, array $callbackQuery): ?string
    {
        $parts = explode(':', $payload);

        if (count($parts) !== 3 || ! ctype_digit($parts[0]) || ! in_array($parts[1], ['a', 'r'], true)
            || ! hash_equals(self::signature((int) $parts[0], $parts[1]), $parts[2])) {
            Log::warning('Telegram: botón de aprobación con datos inválidos.', ['user_id' => $user->id]);

            return 'Esta acción ya no está disponible.';
        }

        $approval = $this->approval((int) $parts[0]);

        if ($approval === null) {
            return 'Esta acción ya no está disponible.';
        }

        if ((int) $approval->user_id !== $user->id) {
            Log::warning('Telegram: botón de aprobación usado por otra persona.', ['user_id' => $user->id, 'approval_id' => $approval->id]);

            return 'Esta aprobación no es tuya.';
        }

        $chatId = (string) ($callbackQuery['message']['chat']['id'] ?? '');
        $messageId = $callbackQuery['message']['message_id'] ?? null;
        $original = (string) ($callbackQuery['message']['text'] ?? '');
        $request = $approval->request;

        if (! $this->stillPending($approval)) {
            $this->edit($chatId, $messageId, $original, $this->outcome($approval));

            return 'Esta solicitud ya estaba decidida.';
        }

        if ($parts[1] === 'r') {
            $this->replies->put($chatId, self::REPLY_KIND, [
                'approval_id' => $approval->id,
                'message_id' => is_int($messageId) ? $messageId : null,
                'text' => $original,
            ], self::REASON_MINUTES);

            $this->client->sendMessage($chatId, "Escribe en un mensaje el motivo para rechazar {$request->key}. Si no quieres rechazarla, escribe ".self::CANCEL_COMMAND.'.');

            return 'Escribe el motivo del rechazo.';
        }

        $error = $this->decide($approval, $user, ApprovalDecision::Approved, null);

        if ($error !== null) {
            return $error;
        }

        $this->edit($chatId, $messageId, $original, '✅ Aprobaste esta solicitud · '.now()->locale('es')->translatedFormat('j M Y, g:i a'));

        return '✅ Aprobada';
    }

    /**
     * The next plain-text message of the chat after ❌: the rejection reason.
     * Returns false when the approval is gone or not theirs.
     */
    public function handleReply(User $user, string $chatId, string $text, array $pending): bool
    {
        if (strtolower(trim($text)) === self::CANCEL_COMMAND) {
            $this->replies->forget($chatId);
            $this->client->sendMessage($chatId, 'Listo, no se rechazó. La aprobación sigue pendiente.');

            return true;
        }

        $approval = $this->approval((int) ($pending['approval_id'] ?? 0));
        $this->replies->forget($chatId);

        if ($approval === null || (int) $approval->user_id !== $user->id) {
            return false;
        }

        $key = $approval->request?->key;

        if (! $this->stillPending($approval)) {
            $this->client->sendMessage($chatId, "{$key} ya estaba decidida: {$this->outcome($approval)}");

            return true;
        }

        $reason = trim($text);
        $error = $this->decide($approval, $user, ApprovalDecision::Rejected, $reason);

        if ($error !== null) {
            $this->client->sendMessage($chatId, $error);

            return true;
        }

        $this->edit($chatId, $pending['message_id'] ?? null, (string) ($pending['text'] ?? ''), "❌ Rechazaste esta solicitud: «{$reason}»");
        $this->client->sendMessage($chatId, "Listo, {$key} quedó rechazada.");

        return true;
    }

    private static function signature(int $approvalId, string $code): string
    {
        $raw = hash_hmac('sha256', self::PREFIX.':'.$approvalId.':'.$code, (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(substr($raw, 0, 10)), '+/', '-_'), '=');
    }

    private function approval(int $id): ?AiRequestApproval
    {
        $approval = AiRequestApproval::query()
            ->whereKey($id)
            ->where('kind', ApprovalKind::Project->value)
            ->first();

        if ($approval === null) {
            return null;
        }

        $request = AiRequest::query()->withoutGlobalScopes()->whereNull('deleted_at')->find($approval->ai_request_id);

        return $request === null ? null : $approval->setRelation('request', $request);
    }

    private function stillPending(AiRequestApproval $approval): bool
    {
        return $approval->decision === ApprovalDecision::Pending
            && $approval->request !== null
            && ApprovalRounds::awaiting($approval->request);
    }

    /**
     * Records the decision; returns an error text for the user or null.
     */
    private function decide(AiRequestApproval $approval, User $user, ApprovalDecision $decision, ?string $comment): ?string
    {
        try {
            $this->decisions->handle($approval->request, $user, ApprovalKind::Project, $decision, $comment, ApprovalChannel::Telegram);
        } catch (AuthorizationException) {
            return 'No puedes decidir esta aprobación (por ejemplo, porque tú la evaluaste). Ábrela en el Gestor TI.';
        } catch (ValidationException $exception) {
            return (string) collect($exception->errors())->flatten()->first();
        }

        return null;
    }

    /**
     * What happened to the approval, for an already decided one.
     */
    private function outcome(AiRequestApproval $approval): string
    {
        $request = $approval->request;

        return match (true) {
            $approval->decision === ApprovalDecision::Approved => '✅ Ya la aprobaste.',
            $approval->decision === ApprovalDecision::Rejected => '❌ Ya la rechazaste.',
            $request?->status === RequestStatus::Rejected => '❌ Ya fue rechazada.',
            $request !== null && ApprovalRounds::awaiting($request) => 'Sigue pendiente.',
            default => '✅ Ya fue aprobada.',
        };
    }

    private function edit(string $chatId, mixed $messageId, string $original, string $line): void
    {
        if ($chatId === '' || ! is_int($messageId)) {
            return;
        }

        $this->client->editMessageText($chatId, $messageId, trim($original."\n\n".$line));
    }
}
