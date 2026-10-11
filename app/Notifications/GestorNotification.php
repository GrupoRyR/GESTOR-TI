<?php

namespace App\Notifications;

use App\Mail\TemplateMail;
use App\Models\AiRequest;
use App\Models\Company;
use App\Models\User;
use App\Notifications\Messages\TelegramMessage;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base class of every notification of the platform.
 *
 * - Channels come from the user's preferences for `eventType()` (catalog in
 *   config/notifications.php), else the catalog default. `telegram` is
 *   dropped when the user has no linked chat, `mail` when there is no email.
 * - Mail leaves from the sender mailbox of the notification's company
 *   (`forCompany()`), else of the recipient's company, else the default
 *   `MAIL_FROM_*`. Never assumes a fixed set of companies.
 * - Mail: the default layout below, or an approved template email
 *   (`templateMail()`, resources/views/mail) when the subclass has one.
 * - Queued (database queue, cron worker), except the `database` channel,
 *   which is written right away so the bell updates immediately.
 *
 * Subclasses implement `eventType()` and `title()`; `body()`, `actionUrl()`
 * and the `to*` methods can be overridden.
 */
abstract class GestorNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Company whose sender mailbox is used (stored as id: serializable).
     */
    public ?int $companyId = null;

    abstract public function eventType(): string;

    abstract public function title(): string;

    public function body(): string
    {
        return '';
    }

    public function actionUrl(): ?string
    {
        return null;
    }

    public function actionText(): string
    {
        return 'Ver en el Gestor TI';
    }

    public function forCompany(Company|int|null $company): static
    {
        $this->companyId = $company instanceof Company ? $company->id : $company;

        return $this;
    }

    public function company(): ?Company
    {
        return $this->companyId === null ? null : Company::query()->find($this->companyId);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = $notifiable instanceof User
            ? app(NotificationPreferences::class)->channelsFor($notifiable, $this->eventType())
            : NotificationPreferences::FALLBACK_CHANNELS;

        return array_values(array_filter($channels, fn (string $channel): bool => match ($channel) {
            'telegram' => (bool) $notifiable->routeNotificationFor('telegram', $this),
            'mail' => (bool) $notifiable->routeNotificationFor('mail', $this),
            default => true,
        }));
    }

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toMail(object $notifiable): MailMessage|Mailable
    {
        $message = $this->mailMessage($notifiable)
            ->subject($this->title())
            ->greeting('Hola'.($notifiable instanceof User ? ', '.$notifiable->name : ''));

        if ($this->body() !== '') {
            $message->line($this->body());
        }

        if ($this->actionUrl() !== null) {
            $message->action($this->actionText(), $this->actionUrl());
        }

        return $message->salutation('Gestor TI RYR');
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $text = $this->title().($this->body() !== '' ? "\n\n".$this->body() : '');
        $message = TelegramMessage::make($text);

        if ($this->actionUrl() !== null) {
            $message->urlButton($this->actionText(), $this->actionUrl());
        }

        return $message;
    }

    /**
     * Payload of the `database` channel (the bell).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->eventType(),
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->actionUrl(),
            'company_id' => $this->companyId,
        ];
    }

    /**
     * An approved template email (App\Mail\TemplateMail) addressed to the
     * notifiable: subclasses with a template return it from `toMail`.
     */
    protected function templateMail(object $notifiable, TemplateMail $mail): TemplateMail
    {
        return $mail->to($notifiable->routeNotificationFor('mail', $this));
    }

    /**
     * The request a notice is about, read fresh when the queued mail is
     * built (no company scope: the queue has no signed-in user).
     */
    protected static function findRequest(string $key): ?AiRequest
    {
        return AiRequest::withoutGlobalScopes()->where('key', $key)->first();
    }

    /**
     * MailMessage whose `from` is the sender mailbox of the company.
     */
    protected function mailMessage(object $notifiable): MailMessage
    {
        $message = new MailMessage;
        $company = $this->senderCompany($notifiable);

        if ($company !== null && filled($company->sender_email)) {
            $message->from($company->sender_email, $company->sender_name ?: null);
        }

        return $message;
    }

    /**
     * The notification's company, else the recipient's home company.
     */
    protected function senderCompany(object $notifiable): ?Company
    {
        $company = $this->company();

        if ($company !== null) {
            return $company;
        }

        return $notifiable instanceof User ? $notifiable->company : null;
    }
}
