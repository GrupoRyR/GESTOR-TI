<?php

namespace Tests\Feature\Mail;

use App\Enums\Ai\RequestStatus;
use App\Mail\TemplateMail;
use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Support\Mail\BrandColor;
use App\Support\Mail\EmailTemplate;
use App\Support\Mail\RequestEmails;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ai\AiTestCase;

/**
 * Template emails of a Gestor IA request: sender, subject, variables,
 * company branding, privacy whitelist and no tracking.
 */
class RequestEmailsTest extends AiTestCase
{
    /** Variable names that carry money, time spent, rates or internal notes. */
    private const SENSITIVE = '/(^|_)(cost|costs|hours|budget|rate|rates|internal)(_|$)/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->segurex->update([
            'short_name' => 'Segurex',
            'sender_email' => 'segurex.info@segurex.com',
            'colors' => ['primary' => '#FF671F', 'accent' => '#666666', 'dark' => '#333333'],
        ]);
        $this->derivados->update([
            'short_name' => 'Derivados',
            'sender_email' => 'derivados.info@derivadoscol.com',
            'colors' => ['primary' => '#183F94', 'accent' => '#6D94E8', 'dark' => '#031D54'],
        ]);
    }

    private function segurexRequest(array $attributes = []): AiRequest
    {
        return $this->request([
            'title' => 'Reporte de comisiones en Excel',
            'hours_per_week' => 6,
            'first_response_due_at' => now()->addDays(3),
            ...$attributes,
        ]);
    }

    private function derivadosRequest(array $attributes = []): AiRequest
    {
        return $this->request([
            'company_id' => $this->derivados->id,
            'area_id' => $this->derivadosVentas->id,
            'requester_id' => $this->derivadosUser->id,
            'created_by_id' => $this->derivadosUser->id,
            'title' => 'Buscador por referencia',
            ...$attributes,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function rendered(TemplateMail $mail): array
    {
        $html = $mail->render();
        $text = (string) $this->invokeBuildView($mail)['text']->toHtml();

        return [$html, $text];
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeBuildView(TemplateMail $mail): array
    {
        return (fn () => $this->buildView())->call($mail);
    }

    public function test_every_template_leaves_from_the_company_mailbox_with_its_subject(): void
    {
        $request = $this->segurexRequest();
        $mails = [
            RequestEmails::newRequest($request),
            RequestEmails::requestReceived($request),
            RequestEmails::statusChanged($request, RequestStatus::Evaluating),
            RequestEmails::itQuestion($request, '¿Qué columnas necesitas?', 'Jhon Castro', now()),
            RequestEmails::delivered($request, $this->paola),
        ];

        foreach ($mails as $mail) {
            $this->assertTrue($mail->hasFrom('segurex.info@segurex.com', 'Segurex · Gestor TI'), $mail->template()->value);
            $this->assertStringStartsWith("[{$request->key}] ", $mail->subject);
            $this->assertSame($mail->template()->subject($mail->variables()), $mail->subject);
            $this->assertEqualsCanonicalizing($mail->template()->allowedVariables(), array_keys($mail->variables()), $mail->template()->value);
        }

        $this->assertSame("[{$request->key}] Nueva solicitud: Reporte de comisiones en Excel", $mails[0]->subject);
        $this->assertSame("[{$request->key}] Recibimos tu solicitud", $mails[1]->subject);
        $this->assertSame("[{$request->key}] Tu solicitud está en evaluación", $mails[2]->subject);
        $this->assertSame("[{$request->key}] TI tiene una pregunta sobre tu solicitud", $mails[3]->subject);
        $this->assertSame("[{$request->key}] Ya está en producción: Reporte de comisiones en Excel", $mails[4]->subject);
    }

    public function test_html_and_text_parts_carry_the_request_data(): void
    {
        $request = $this->segurexRequest();
        [$html, $text] = $this->rendered(RequestEmails::newRequest($request));

        $this->assertStringContainsString('Llegó una solicitud nueva de Ventas', $html);
        $this->assertStringContainsString('Juan Pérez', RequestEmails::newRequest($this->segurexRequest(['requester_id' => $this->juan->id]))->render());
        $this->assertStringContainsString('6 h por semana', $html);
        $this->assertStringContainsString(route('ai.requests.show', $request->key), $html);
        $this->assertStringContainsString(route('profile.edit'), $html);
        $this->assertStringContainsString('Cambiar mis avisos', $html);

        $this->assertStringContainsString("Llegó una solicitud nueva de Ventas\n\n{$request->key} · Recibida", $text);
        $this->assertStringContainsString('Ver solicitud: '.route('ai.requests.show', $request->key), $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_the_text_part_is_entity_decoded(): void
    {
        $request = $this->segurexRequest();
        [$html, $text] = $this->rendered(RequestEmails::itQuestion($request, '¿"Ventas & Compras" o solo <Ventas>?', 'Jhon Castro', now()));

        $this->assertStringContainsString('&quot;Ventas &amp; Compras&quot; o solo &lt;Ventas&gt;', $html);
        $this->assertStringContainsString('"¿"Ventas & Compras" o solo <Ventas>?"', $text);
    }

    public function test_the_company_sets_sender_color_and_logo(): void
    {
        Storage::fake('public');
        $this->segurex->update(['logo_path' => UploadedFile::fake()->image('segurex.png', 350, 56)->store('logos', 'public')]);
        $this->derivados->update(['logo_path' => 'logos/derivados.svg']);

        $segurex = RequestEmails::requestReceived($this->segurexRequest())->variables();
        $derivados = RequestEmails::requestReceived($this->derivadosRequest())->variables();

        $this->assertSame('Segurex', $segurex['company_name']);
        $this->assertSame('segurex.info@segurex.com', $segurex['company_sender']);
        $this->assertSame(BrandColor::button('#FF671F'), $segurex['brand_color']);
        $this->assertSame('#CD4B00', $segurex['brand_color'], 'same derivation as resources/js/lib/brand.ts');
        $this->assertStringEndsWith('.png', $segurex['logo_url']);
        $this->assertStringStartsWith('http', $segurex['logo_url']);
        $this->assertSame($segurex['logo_url'], $segurex['logo_dark_url'], 'no dark logo: the light one');
        $this->assertSame(175, $segurex['logo_width']);

        $this->assertSame('Derivados', $derivados['company_name']);
        $this->assertSame('derivados.info@derivadoscol.com', $derivados['company_sender']);
        $this->assertSame('#183F94', $derivados['brand_color']);
        $this->assertSame(asset('brand/logo-derivados.png'), $derivados['logo_url'], 'an SVG upload is never mailed: the deploy PNG is');
        $this->assertSame(140, $derivados['logo_width']);

        foreach ([$segurex, $derivados] as $variables) {
            $this->assertStringNotContainsString('.svg', $variables['logo_url'].$variables['logo_dark_url']);
        }
    }

    public function test_a_stored_button_color_wins_over_the_derived_one(): void
    {
        $this->segurex->update(['colors' => [...$this->segurex->colors, 'button' => '#c4470f']]);

        $this->assertSame('#C4470F', RequestEmails::requestReceived($this->segurexRequest())->variables()['brand_color']);
    }

    public function test_requester_templates_only_receive_their_whitelist(): void
    {
        foreach (EmailTemplate::cases() as $template) {
            if (! $template->isForRequester()) {
                continue;
            }

            foreach ($template->allowedVariables() as $name) {
                $this->assertDoesNotMatchRegularExpression(self::SENSITIVE, $name, "{$template->value} whitelists {$name}");
            }

            $mail = TemplateMail::make($template, $this->segurex, [
                'request_key' => 'GIA-1',
                'cost_total' => '$ 1.000.000',
                'hours_used' => 92,
                'internal_note' => 'Solo TI',
                'rate_per_hour' => 50000,
                'budget' => 10,
            ]);

            foreach (array_keys($mail->variables()) as $name) {
                $this->assertDoesNotMatchRegularExpression(self::SENSITIVE, $name, "{$template->value} received {$name}");
            }

            $this->assertArrayNotHasKey('cost_total', $mail->buildViewData());
        }
    }

    public function test_requester_mails_carry_no_cost_hours_or_internal_data(): void
    {
        $request = $this->segurexRequest(['hours_per_week' => 123.5]);
        $request->comments()->create(['user_id' => $this->jhon->id, 'body' => 'NOTA-INTERNA-XYZ', 'visibility' => 'internal']);

        $mails = [
            RequestEmails::requestReceived($request),
            RequestEmails::statusChanged($request, RequestStatus::PendingApproval),
            RequestEmails::itQuestion($request, '¿Algo más?', 'Jhon Castro', now()),
            RequestEmails::delivered($request, $this->paola),
        ];

        foreach ($mails as $mail) {
            [$html, $text] = $this->rendered($mail);

            foreach (array_keys($mail->variables()) as $name) {
                $this->assertDoesNotMatchRegularExpression(self::SENSITIVE, $name);
            }

            $this->assertStringNotContainsString('123,5', $text, $mail->template()->value);
            $this->assertStringNotContainsString('123.5', $html.$text, $mail->template()->value);
            $this->assertStringNotContainsString('por semana', $html.$text, $mail->template()->value);
            $this->assertStringNotContainsString('NOTA-INTERNA-XYZ', $html.$text);
        }
    }

    public function test_no_tracking_pixels_or_rewritten_links(): void
    {
        $request = $this->segurexRequest(['status' => RequestStatus::InProduction]);
        $mails = [
            RequestEmails::newRequest($request),
            RequestEmails::requestReceived($request),
            RequestEmails::statusChanged($request, RequestStatus::Evaluating),
            RequestEmails::itQuestion($request, '¿Qué?', 'Jhon Castro', now()),
            RequestEmails::delivered($request, $this->paola),
        ];

        foreach ($mails as $mail) {
            $html = $mail->render();
            preg_match_all('/<img\b[^>]*>/i', $html, $images);

            $this->assertCount(2, $images[0], 'only the light and dark logos');

            foreach ($images[0] as $img) {
                $this->assertDoesNotMatchRegularExpression('/\b(width|height)="[01]"/', $img);
            }

            preg_match_all('/href="([^"]+)"/', $html, $links);

            foreach ($links[1] as $link) {
                $this->assertStringStartsWith(url('/'), html_entity_decode($link));
                $this->assertDoesNotMatchRegularExpression('/utm_|track|redirect/i', $link);
            }
        }
    }

    public function test_status_changed_draws_the_phase_rail(): void
    {
        $request = $this->derivadosRequest(['status' => RequestStatus::PendingApproval]);
        $variables = RequestEmails::statusChanged($request, RequestStatus::PendingApproval)->variables();

        $this->assertSame('pendiente de aprobación', $variables['status_text']);
        $this->assertSame(['◐', 'amber', '#FFF4DB', '#7A4F00'], [$variables['status_glyph'], $variables['status_family'], $variables['status_bg'], $variables['status_fg']]);
        $this->assertSame('aprobación', $variables['current_phase']);
        $this->assertSame(['done', 'current', 'pending', 'pending', 'pending'], array_column($variables['phases'], 'state'));
        $this->assertSame('Aprobación · aquí está ahora', $variables['phases'][1]['label']);
        $this->assertSame(1, $variables['roadmap_position']);
        $this->assertSame('Ventas', $variables['roadmap_area']);

        $this->assertNull(RequestEmails::statusChanged($request, RequestStatus::Planned)->variables()['roadmap_position']);
    }

    public function test_it_question_reply_block_follows_the_setting_and_is_off_by_default(): void
    {
        $request = $this->segurexRequest();
        $mail = RequestEmails::itQuestion($request, '¿Cada cuánto?', 'Jhon Castro', now());

        $this->assertFalse($mail->variables()['reply_by_email']);
        $this->assertStringNotContainsString('También puedes responder este correo', $mail->render());
        $this->assertStringContainsString('Jhon Castro', $mail->render());
        $this->assertStringContainsString('hoy, ', $mail->variables()['asked_at']);

        app(Settings::class)->set('mail.reply_by_email', true);

        $this->assertStringContainsString('También puedes responder este correo', RequestEmails::itQuestion($request, '¿Cada cuánto?', 'Jhon Castro', now())->render());
    }

    public function test_delivered_names_the_release_and_carries_signed_rating_links(): void
    {
        $product = AiProduct::factory()->create(['name' => 'Portafolio de productos']);
        $request = $this->derivadosRequest(['status' => RequestStatus::InProduction, 'product_id' => $product->id]);
        $version = AiProductVersion::factory()->for($product, 'product')->create([
            'version' => 'v1.8.2',
            'released_at' => '2026-10-02',
            'user_notes' => "Ahora puedes buscar por referencia.\n\nLos resultados aparecen mientras escribes.",
        ]);
        $version->requests()->attach($request->id);

        $variables = RequestEmails::delivered($request, $this->derivadosUser)->variables();

        $this->assertSame('Diego', $variables['requester_first_name']);
        $this->assertSame('Portafolio de productos', $variables['product_name']);
        $this->assertSame('1.8.2', $variables['release_version']);
        $this->assertSame('viernes 2 oct', $variables['released_on']);
        $this->assertSame(['Ahora puedes buscar por referencia.', 'Los resultados aparecen mientras escribes.'], $variables['change_paragraphs']);
        $this->assertSame(route('ai.ratings.create', $request->key), $variables['action_url']);

        foreach (range(1, 5) as $score) {
            $url = $variables["rating_url_{$score}"];
            $this->assertStringContainsString("/ai/requests/{$request->key}/rating/{$score}?", $url);
            $this->assertStringContainsString('signature=', $url);
            $this->assertStringContainsString('expires=', $url);
            $this->assertStringContainsString("user={$this->derivadosUser->id}", $url);
        }
    }
}
