<?php

namespace App\Notifications;

/**
 * To Jhon (the vault holders) every time someone shows, in the Asistente
 * TI chat, a password the assistant delivered (PROMPT 6.8.2; event
 * `assistant.credential.revealed`, Telegram required). Names only: the
 * value never travels in a notice.
 */
class AssistantCredentialRevealed extends GestorNotification
{
    public function __construct(
        public readonly string $personName,
        public readonly string $employeeName,
        public readonly string $systemName,
        public readonly string $channel,
        public readonly string $at,
    ) {}

    public function eventType(): string
    {
        return 'assistant.credential.revealed';
    }

    public function title(): string
    {
        return "Asistente TI: {$this->personName} vio la contraseña de {$this->systemName} de {$this->employeeName}";
    }

    public function body(): string
    {
        return "Desde {$this->channel}, {$this->at}. Si no fue autorizado, cambia esa contraseña y revisa la auditoría.";
    }

    public function actionUrl(): ?string
    {
        return route('admin.audit.index');
    }

    public function actionText(): string
    {
        return 'Ver la auditoría';
    }
}
