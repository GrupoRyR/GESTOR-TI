<?php

namespace App\Mail;

use App\Domain\Ai\ProjectReports;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Project report by e-mail (HTML body + the same content as PDF). The
 * content was built for this recipient: `budget` is null unless they may
 * see time and cost (App\Domain\Ai\ProjectReports::send). Sent from the
 * project company's sender mailbox when it has one.
 */
class ProjectReportMail extends Mailable
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
    ) {}

    public function envelope(): Envelope
    {
        $project = $this->content['project'];
        $date = Carbon::parse((string) $this->content['as_of'])->format('d/m/Y');

        return new Envelope(
            from: $this->senderEmail !== null ? new Address($this->senderEmail, $this->senderName) : null,
            subject: "{$project['key']} {$project['name']}: informe al {$date}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.project-report', with: [
            'content' => $this->content,
            'sections' => $this->sections,
        ]);
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
