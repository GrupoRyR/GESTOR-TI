<?php

namespace App\Mail;

use App\Domain\Ai\ProjectReports;
use App\Models\Company;
use App\Support\Mail\EmailTemplate;
use App\Support\Mail\ProjectEmails;
use Illuminate\Mail\Attachment;

/**
 * Weekly project report by e-mail: the approved `project-report` template
 * (HTML + text) plus the same content as PDF.
 *
 * The content was built for the recipient's group
 * (App\Domain\Ai\ProjectReports::send): `budget` carries money only when
 * they may see time and cost (`ai.costs.view`, internal version, section
 * chosen). The view data follows: `can_view_costs` and the cost variables
 * for that group; for everyone else the data has no cost, hour, budget,
 * rate or approver key at all (ProjectEmails + the template whitelist).
 *
 * From, branding and subject come from the project's company
 * (TemplateMail); `$senderEmail` / `$senderName` are kept for the callers.
 */
class ProjectReportMail extends TemplateMail
{
    /**
     * @param  array<string, mixed>  $content  design `ReportContent`
     * @param  list<string>  $sections
     */
    public function __construct(
        public array $content,
        public array $sections,
        public string $version,
        public ?string $senderEmail = null,
        public ?string $senderName = null,
    ) {
        $company = isset($content['company_id']) ? Company::query()->find($content['company_id']) : null;

        parent::__construct(
            EmailTemplate::ProjectReport,
            self::data(EmailTemplate::ProjectReport, $company, ProjectEmails::projectReport($content, $sections)),
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $key = (string) $this->content['project']['key'];

        return [
            Attachment::fromData(fn () => $this->pdfBytes(), "informe-{$key}.pdf")->withMime('application/pdf'),
        ];
    }

    public function pdfBytes(): string
    {
        return ProjectReports::pdf($this->content, $this->sections)['bytes'];
    }
}
