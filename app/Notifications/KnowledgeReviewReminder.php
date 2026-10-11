<?php

namespace App\Notifications;

/**
 * Weekly digest to a knowledge manager (PROMPT 6.8.1,
 * `assistant:knowledge-review-reminders`), event `assistant.knowledge.review`:
 * how many of the cards they can see are past their review date, with the
 * first titles. Keys and titles only.
 */
class KnowledgeReviewReminder extends GestorNotification
{
    /** Titles listed in the body. */
    public const LISTED = 5;

    /**
     * @param  list<string>  $titles  "K-031 · Informe de ventas diario", oldest review first
     */
    public function __construct(
        public readonly int $count,
        public readonly array $titles,
    ) {}

    public function eventType(): string
    {
        return 'assistant.knowledge.review';
    }

    public function title(): string
    {
        return $this->count === 1
            ? 'Base de conocimiento: 1 ficha con la revisión vencida'
            : "Base de conocimiento: {$this->count} fichas con la revisión vencida";
    }

    public function body(): string
    {
        $more = $this->count - count($this->titles);
        $list = implode('; ', $this->titles).($more > 0 ? " y {$more} más" : '');

        return "Pasaron 6 meses sin revisarlas: {$list}. Ábrelas, confirma que siguen vigentes y márcalas como revisadas.";
    }

    public function actionUrl(): ?string
    {
        return route('assistant.knowledge.index', ['vencidas' => 1]);
    }

    public function actionText(): string
    {
        return 'Ver fichas vencidas';
    }
}
