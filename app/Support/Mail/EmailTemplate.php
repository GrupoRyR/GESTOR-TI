<?php

namespace App\Support\Mail;

/**
 * The approved email templates (resources/emails/README.md) the app sends
 * as Blade views (resources/views/mail/{html,text}/<name>.blade.php).
 *
 * Each template declares the exact variables its view receives: anything
 * else is dropped before rendering (TemplateMail), so a requester email can
 * never carry costs, hours, rates or internal notes by accident.
 */
enum EmailTemplate: string
{
    case NewRequest = 'new-request';
    case RequestReceived = 'request-received';
    case StatusChanged = 'status-changed';
    case ItQuestion = 'it-question';
    case Delivered = 'delivered';
    case Requirement = 'requirement';
    case ProjectReport = 'project-report';

    /**
     * Variables every template receives (README "Shared variables").
     */
    public const SHARED = [
        'company_name', 'company_sender', 'brand_color', 'logo_url', 'logo_dark_url', 'logo_width',
        'action_url', 'preferences_url',
    ];

    /**
     * `project-report` variables that live only inside `@if can_view_costs`:
     * time, cost, dates against the approval and the approvers. Recipients
     * without `ai.costs.view` get none of them (README "Privacy rules").
     */
    public const COST_VARIABLES = [
        'summary_with_costs', 'hours_used', 'hours_budget', 'hours_left', 'hours_percent',
        'cost_used', 'cost_budget', 'cost_percent', 'cost_bar_color', 'cost_alert',
        'projected_date', 'approved_date', 'days_late', 'cost_reapproval_limit', 'approvers_and', 'approvers_or',
    ];

    public function htmlView(): string
    {
        return 'mail.html.'.$this->value;
    }

    public function textView(): string
    {
        return 'mail.text.'.$this->value;
    }

    /**
     * Requester templates never receive costs, rates, hours or internal notes.
     */
    public function isForRequester(): bool
    {
        return in_array($this, [self::RequestReceived, self::StatusChanged, self::ItQuestion, self::Delivered], true);
    }

    /**
     * Whitelist of the template's own variables (besides SHARED).
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return match ($this) {
            self::NewRequest => ['request_key', 'request_title', 'area_name', 'requester_name', 'time_spent_today', 'first_response_due'],
            self::RequestReceived => ['request_key', 'request_title', 'review_due_date'],
            self::StatusChanged => [
                'request_key', 'request_title', 'status_label', 'status_text', 'status_glyph', 'status_family',
                'status_bg', 'status_fg', 'current_phase', 'phases', 'next_step_text', 'roadmap_position', 'roadmap_area',
            ],
            self::ItQuestion => ['request_key', 'request_title', 'asker_name', 'asked_at', 'question_text', 'reply_by_email'],
            self::Delivered => [
                'request_key', 'request_title', 'requester_first_name', 'product_name', 'release_version', 'released_on',
                'change_title', 'change_paragraphs', 'rating_url_1', 'rating_url_2', 'rating_url_3', 'rating_url_4', 'rating_url_5',
            ],
            self::Requirement => [
                'project_key', 'request_key', 'request_title', 'area_name', 'requirement_title', 'requested_by_name',
                'is_overdue', 'days_waiting', 'due_date', 'due_note', 'due_summary', 'blocked_task', 'needs',
                'asks_for_secrets', 'reminder_text',
            ],
            self::ProjectReport => [
                'can_view_costs', 'project_key', 'project_name', 'project_title', 'request_key', 'requested_by',
                'report_date', 'report_date_short', 'status_label', 'status_glyph', 'status_family', 'status_bg', 'status_fg',
                'summary', 'phases', 'tasks_done', 'tasks_total', 'tasks_percent', 'criteria_met', 'criteria_total',
                'criteria_percent', 'criteria_met_marks', 'criteria_pending_marks', 'done_items', 'next_items', 'risks',
                ...self::COST_VARIABLES,
            ],
        };
    }

    /**
     * Shared + own variables. Without costs (`project-report` for people
     * without `ai.costs.view`), the cost variables are left out entirely.
     *
     * @return list<string>
     */
    public function allowedVariables(bool $withCosts = true): array
    {
        $variables = [...self::SHARED, ...$this->variables()];

        return $withCosts ? $variables : array_values(array_diff($variables, self::COST_VARIABLES));
    }

    /**
     * Subject line, as in the `subject:` comment of the source template.
     *
     * @param  array<string, mixed>  $data
     */
    public function subject(array $data): string
    {
        $key = (string) ($data['request_key'] ?? '');
        $project = (string) ($data['project_key'] ?? '');

        return match ($this) {
            self::NewRequest => "[{$key}] Nueva solicitud: ".($data['request_title'] ?? ''),
            self::RequestReceived => "[{$key}] Recibimos tu solicitud",
            self::StatusChanged => "[{$key}] Tu solicitud está ".($data['status_text'] ?? ''),
            self::ItQuestion => "[{$key}] TI tiene una pregunta sobre tu solicitud",
            self::Delivered => "[{$key}] Ya está en producción: ".($data['request_title'] ?? ''),
            self::Requirement => "[{$project}] TI necesita ".($data['requirement_title'] ?? '').' · '.($data['due_summary'] ?? ''),
            self::ProjectReport => "[{$project}] Informe semanal: ".($data['project_name'] ?? '').' al '.($data['report_date_short'] ?? ''),
        };
    }
}
