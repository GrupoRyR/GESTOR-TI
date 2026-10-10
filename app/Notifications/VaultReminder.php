<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;

/**
 * To the vault holders 30, 15 and 7 days before a credential expires or is
 * due for rotation (PROMPT 6.4.10, `ai:vault-reminders`), event
 * `vault.reminder`. Names and dates only.
 */
class VaultReminder extends GestorNotification
{
    public const EXPIRY = 'expiry';

    public const ROTATION = 'rotation';

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /**
     * @param  'expiry'|'rotation'  $kind
     * @param  string  $date  YYYY-MM-DD
     */
    public function __construct(
        public readonly int $itemId,
        public readonly string $itemName,
        public readonly string $kind,
        public readonly int $days,
        public readonly string $date,
    ) {}

    public function eventType(): string
    {
        return 'vault.reminder';
    }

    public function title(): string
    {
        return $this->kind === self::ROTATION
            ? "Bóveda: toca rotar {$this->itemName} en {$this->days} días"
            : "Bóveda: {$this->itemName} vence en {$this->days} días";
    }

    public function body(): string
    {
        $date = CarbonImmutable::parse($this->date);
        $label = $date->day.' '.self::MONTHS[$date->month - 1].' '.$date->year;

        return $this->kind === self::ROTATION
            ? "La rotación está programada para el {$label}. Cámbiala en el servicio y luego en la bóveda."
            : "Vence el {$label}. Renuévala antes para que nada deje de funcionar.";
    }

    public function actionUrl(): ?string
    {
        return route('ai.vault.index', ['item' => $this->itemId]);
    }

    public function actionText(): string
    {
        return 'Abrir la bóveda';
    }
}
