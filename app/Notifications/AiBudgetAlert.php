<?php

namespace App\Notifications;

use App\Domain\Ai\ProjectCosts;

/**
 * To the project approvers when a project reaches 80 % or passes 100 % of
 * what they approved (App\Actions\Ai\WatchProjectBudget), event
 * `ai.project.budget`. Hours and percentages only: no money in the text.
 */
class AiBudgetAlert extends GestorNotification
{
    public function __construct(
        public readonly string $projectKey,
        public readonly string $projectName,
        public readonly int $level,
        public readonly ?int $hoursPercent,
        public readonly ?int $costPercent,
        public readonly float $actualHours,
        public readonly float $estimateHours,
    ) {}

    public function eventType(): string
    {
        return 'ai.project.budget';
    }

    public function title(): string
    {
        return $this->level >= 100
            ? "{$this->projectKey} pasó el 100 % de lo aprobado"
            : "{$this->projectKey} llegó al 80 % de lo aprobado";
    }

    public function body(): string
    {
        $parts = ["«{$this->projectName}»."];

        if ($this->hoursPercent !== null) {
            $parts[] = 'Horas: '.ProjectCosts::hours($this->actualHours).' de '.ProjectCosts::hours($this->estimateHours)." ({$this->hoursPercent} %).";
        }

        if ($this->costPercent !== null) {
            $parts[] = "Costo: {$this->costPercent} % de lo aprobado.";
        }

        return implode(' ', $parts);
    }

    /**
     * No link: approvers without `ai.manage` have no project page yet
     * (flagged for design); the re-approval notice links the approval.
     */
    public function actionUrl(): ?string
    {
        return null;
    }
}
