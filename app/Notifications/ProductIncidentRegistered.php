<?php

namespace App\Notifications;

use App\Models\AiProduct;
use App\Models\AiProductIncident;

/**
 * To the people chosen in the incident drawer (and the product owner when
 * asked) after an incident is registered by hand (C-IncidentForm: "Avisar a
 * … por correo y Telegram").
 *
 * There is no own event type in config/notifications.php yet, so it rides
 * the alert events: a critical incident is `alert.critical`, any other
 * `alert.info` (both mail, Telegram and bell by default).
 */
class ProductIncidentRegistered extends GestorNotification
{
    public function __construct(
        public readonly string $productSlug,
        public readonly string $productName,
        public readonly string $incidentTitle,
        public readonly string $impact,
        public readonly bool $resolved,
        public readonly string $startedAt,
        public readonly string $reporterName,
    ) {}

    public static function for(AiProduct $product, AiProductIncident $incident, string $reporterName): self
    {
        return new self(
            $product->slug,
            $product->name,
            $incident->title,
            (string) $incident->impact,
            $incident->resolved_at !== null,
            $incident->started_at->timezone('America/Bogota')->format('d/m/Y H:i'),
            $reporterName,
        );
    }

    public function eventType(): string
    {
        return $this->impact === 'critical' ? 'alert.critical' : 'alert.info';
    }

    public function title(): string
    {
        return ($this->resolved ? 'Incidente resuelto' : 'Incidente')." en {$this->productName}: {$this->incidentTitle}";
    }

    public function body(): string
    {
        $impact = AiProductIncident::IMPACT_LABELS[$this->impact] ?? $this->impact;

        return "Impacto {$impact}. Empezó el {$this->startedAt}. Lo registró {$this->reporterName}.";
    }

    public function actionUrl(): ?string
    {
        return route('ai.products.show', ['product' => $this->productSlug]);
    }

    public function actionText(): string
    {
        return 'Ver el producto';
    }
}
