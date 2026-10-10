<?php

namespace App\Notifications;

/**
 * To the vault holders (`ai.vault.access`) every time someone views, copies,
 * creates, changes or deletes a credential (PROMPT 6.4.10), event
 * `vault.accessed` (Telegram is a required channel). Names only: the value
 * never travels in a notice.
 */
class VaultAccessed extends GestorNotification
{
    private const VERBS = [
        'view' => 'vio',
        'copy' => 'copió',
        'create' => 'creó',
        'update' => 'cambió',
        'delete' => 'borró',
    ];

    public function __construct(
        public readonly string $action,
        public readonly ?int $itemId,
        public readonly string $itemName,
        public readonly string $personName,
        public readonly string $from,
        public readonly string $at,
    ) {}

    public function eventType(): string
    {
        return 'vault.accessed';
    }

    public function title(): string
    {
        $verb = self::VERBS[$this->action] ?? $this->action;

        return "Bóveda: {$this->personName} {$verb} {$this->itemName}";
    }

    public function body(): string
    {
        return "Desde {$this->from}, {$this->at}. Si no fuiste tú, cambia esa credencial y revisa el registro de accesos.";
    }

    public function actionUrl(): ?string
    {
        return $this->action === 'delete' || $this->itemId === null
            ? route('ai.vault.index')
            : route('ai.vault.index', ['item' => $this->itemId]);
    }

    public function actionText(): string
    {
        return 'Abrir la bóveda';
    }
}
