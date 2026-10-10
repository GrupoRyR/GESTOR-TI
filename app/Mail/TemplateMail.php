<?php

namespace App\Mail;

use App\Models\Company;
use App\Support\Mail\EmailTemplate;
use App\Support\Mail\MailBranding;
use Illuminate\Mail\Mailable;
use Illuminate\Support\HtmlString;

/**
 * One email built from an approved template (HTML + plain text):
 *
 * - From: the record's company mailbox, as "{company_name} · Gestor TI";
 * - Subject: the template's;
 * - View data: the shared company variables (MailBranding) plus the given
 *   ones, filtered to the template's whitelist (EmailTemplate), so nothing
 *   outside it (costs, hours, internal notes…) ever reaches the view;
 * - `preferences_url`: "Cambiar mis avisos" (Perfil).
 *
 * The plain-text part goes through the same escaped Blade echoes as the
 * HTML, so it is entity-decoded before sending. No tracking of any kind.
 * Built inside the queued notification (`toMail`), so it is never queued
 * on its own.
 */
class TemplateMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $variables  already whitelisted
     */
    private function __construct(
        private readonly EmailTemplate $template,
        private readonly array $variables,
    ) {
        $this->subject($template->subject($variables))
            ->from($variables['company_sender'], $variables['company_name'].' · Gestor TI')
            ->view($template->htmlView())
            ->text($template->textView());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(EmailTemplate $template, ?Company $company, array $data): self
    {
        $variables = [
            ...MailBranding::for($company),
            'preferences_url' => route('profile.edit'),
            ...$data,
        ];

        return new self($template, array_intersect_key($variables, array_flip($template->allowedVariables())));
    }

    public function template(): EmailTemplate
    {
        return $this->template;
    }

    /**
     * The exact data the views receive.
     *
     * @return array<string, mixed>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildViewData(): array
    {
        return $this->variables;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildView(): array
    {
        $text = view($this->template->textView(), $this->variables)->render();

        return [
            'html' => $this->template->htmlView(),
            'text' => new HtmlString(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ];
    }
}
