<?php

namespace App\Telegram\Assistant;

use App\Actions\Ai\Vault\VaultHolders;
use App\Domain\Assistant\CredentialDelivery;
use App\Models\AssistantMessage;
use App\Models\EmployeeAccess;
use App\Models\User;
use App\Notifications\AssistantCredentialRevealed;
use App\Services\Credentials\EmployeeCredentialService;
use App\Services\Telegram\TelegramClient;
use App\Telegram\TelegramHtml;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Password delivery on Telegram (PROMPT 6.8.2, telegram-format.md §4) for
 * a `credential_notice` the engine wrote. The value is read here, by the
 * Gestor, through EmployeeCredentialService::reveal() (audited, channel
 * `telegram`) and goes straight to Telegram: never to the AI provider,
 * never to the conversation.
 *
 * 1. A short notice in the conversation's chat.
 * 2. The credential in a SEPARATE protected message (`<code>`), deleted
 *    after `gestor.telegram.credential_seconds` (60) — or edited to
 *    "Mensaje borrado por seguridad" when the delete fails; the outcome is
 *    audited (App\Jobs\DeleteTelegramMessage).
 * 3. The vault holders are alerted like a reveal in the app chat.
 *
 * Both permissions are checked again before reading the value.
 */
final class CredentialSender
{
    public const DELETED_TEXT = 'Mensaje borrado por seguridad';

    public const NOT_SENT = 'No pude enviar la contraseña. Intenta otra vez en unos minutos.';

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public function __construct(
        private readonly TelegramClient $client,
        private readonly EmployeeCredentialService $credentials,
        private readonly VaultHolders $holders,
    ) {}

    public function deliver(User $user, string $chatId, AssistantMessage $notice): void
    {
        $access = EmployeeAccess::query()
            ->with([
                'employee' => fn ($query) => $query->withoutGlobalScopes()->with(['area' => fn ($area) => $area->withoutGlobalScopes(), 'company']),
                'system',
            ])
            ->find((int) $notice->data('access_id'));

        if ($access === null || $access->employee === null) {
            $this->send($chatId, 'Ese acceso ya no existe.');

            return;
        }

        if (! CredentialDelivery::allowed($user)) {
            $this->send($chatId, CredentialDelivery::REFUSED);

            return;
        }

        try {
            $value = $this->credentials->reveal($user, $access, 'telegram');
        } catch (AuthorizationException) {
            $this->send($chatId, CredentialDelivery::REFUSED);

            return;
        }

        $system = (string) $access->system?->name;
        $person = (string) $access->employee->name;
        $seconds = (int) config('gestor.telegram.credential_seconds', 60);

        $this->send($chatId, "Listo. Te envío la contraseña de {$system} de {$person} en un mensaje aparte.");

        $sent = $this->client->sendEphemeral(
            $chatId,
            $this->credentialText($access, $value, $seconds),
            $seconds,
            ['parse_mode' => 'HTML'],
            self::DELETED_TEXT,
            $access->id,
            $user->id,
        );
        unset($value);

        if ($sent === null) {
            Log::warning('Telegram: no se pudo enviar el mensaje con la contraseña.', ['user_id' => $user->id, 'access_id' => $access->id]);
            $this->send($chatId, self::NOT_SENT);

            return;
        }

        $this->alert($user, $person, $system);
    }

    private function credentialText(EmployeeAccess $access, string $value, int $seconds): string
    {
        $employee = $access->employee;
        $place = implode(' · ', array_filter([
            $employee?->area?->name,
            $employee?->company?->short_name ?: $employee?->company?->name,
        ], fn (?string $part) => filled($part)));

        return implode("\n", array_filter([
            TelegramHtml::bold($access->system?->name.' · '.$employee?->name),
            $place === '' ? null : TelegramHtml::escape($place),
            filled($access->username) ? 'Usuario: '.TelegramHtml::escape((string) $access->username) : null,
            'Contraseña: <code>'.TelegramHtml::escape($value).'</code>',
            "Este mensaje se borra en {$seconds} s",
            TelegramHtml::italic('No pasó por la IA · quedó en la auditoría'),
        ], fn (?string $line) => $line !== null));
    }

    private function alert(User $user, string $person, string $system): void
    {
        $holders = $this->holders->all();
        if ($holders->isEmpty()) {
            return;
        }

        $now = now()->timezone('America/Bogota');

        Notification::send($holders, new AssistantCredentialRevealed(
            $user->name,
            $person,
            $system,
            'Telegram',
            $now->day.' '.self::MONTHS[$now->month - 1].' '.$now->year.', '.$now->format('H:i'),
        ));
    }

    private function send(string $chatId, string $text): void
    {
        $this->client->sendMessage($chatId, TelegramHtml::escape($text), [], TelegramHtml::OPTIONS);
    }
}
