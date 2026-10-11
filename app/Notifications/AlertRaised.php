<?php

namespace App\Notifications;

use App\Models\Alert;
use App\Notifications\Messages\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * An alert of `AlertService` (event type `alert.{level}`).
 */
class AlertRaised extends GestorNotification
{
    public function __construct(public Alert $alert)
    {
        $this->forCompany($alert->company_id);
    }

    public function eventType(): string
    {
        return 'alert.'.$this->alert->level;
    }

    public function title(): string
    {
        return $this->alert->title;
    }

    public function body(): string
    {
        return (string) $this->alert->body;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return parent::toMail($notifiable)
            ->subject('['.$this->alert->levelLabel().'] '.$this->alert->title)
            ->level($this->alert->level === Alert::LEVEL_CRITICAL ? 'error' : 'info');
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $prefix = match ($this->alert->level) {
            Alert::LEVEL_CRITICAL => '🔴',
            Alert::LEVEL_WARNING => '🟠',
            default => 'ℹ️',
        };

        $message = parent::toTelegram($notifiable);
        $message->text = $prefix.' Alerta '.mb_strtolower($this->alert->levelLabel()).': '.$message->text;

        if ($this->alert->occurrences > 1) {
            $message->text .= "\n\nOcurrencias: ".$this->alert->occurrences;
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'alert_id' => $this->alert->id,
            'level' => $this->alert->level,
            'source' => $this->alert->source,
            'title' => $this->alert->title,
            'body' => $this->alert->body,
            'company_id' => $this->alert->company_id,
        ];
    }
}
