<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiIntegrationRequest;
use App\Http\Requests\Admin\UpdateGithubIntegrationRequest;
use App\Mail\IntegrationTestMail;
use App\Mail\Transport\GraphMailException;
use App\Models\Company;
use App\Models\User;
use App\Services\Microsoft\GraphClient;
use App\Services\Telegram\TelegramClient;
use App\Support\Integrations\AiProviderSettings;
use App\Support\Integrations\IntegrationsOverview;
use App\Support\Integrations\MailIntegration;
use App\Support\Integrations\TelegramWebhookStatus;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Administración › Integraciones (`Admin/Integrations/Index`): status of
 * Microsoft, mail, Telegram, GitHub and the AI provider, the test buttons
 * and the two editable settings (GitHub organization, AI provider/model/cap).
 * Keys and passwords live only in `.env`: nothing secret is shown, flashed
 * or logged here. Route-gated by `settings.manage`; test actions are
 * rate-limited.
 */
class IntegrationController extends Controller
{
    public function index(IntegrationsOverview $overview): Response
    {
        return Inertia::render('Admin/Integrations/Index', $overview->toProps());
    }

    /**
     * Requests a fresh app-only token for every active company with a
     * tenant: proves the app credentials and each tenant's consent.
     */
    public function testMicrosoft(GraphClient $graph): RedirectResponse
    {
        if (! GraphClient::isConfigured()) {
            return back()->with('error', IntegrationsOverview::NO_CREDENTIALS_ERROR);
        }

        $companies = Company::query()->syncable()->orderBy('name')->get();

        if ($companies->isEmpty()) {
            return back()->with('error', 'Ninguna empresa activa tiene inquilino de Microsoft.');
        }

        $failed = false;
        $lines = $companies->map(function (Company $company) use ($graph, &$failed): string {
            try {
                $graph->token((string) $company->tenant_id, fresh: true);

                return $company->short_name.': conectó.';
            } catch (Throwable $exception) {
                $failed = true;
                Log::warning('Integraciones: falló la prueba de Microsoft.', ['company_id' => $company->id, 'exception' => $exception::class]);

                return $company->short_name.': '.self::microsoftFailure($exception);
            }
        });

        return back()->with($failed ? 'error' : 'success', $lines->implode(' '));
    }

    public function testMail(Request $request, MailIntegration $mail): RedirectResponse
    {
        if ($mail->transport() === null) {
            return back()->with('error', 'El correo todavía no está configurado en el servidor (Microsoft Graph o SMTP).');
        }

        /** @var User $user */
        $user = $request->user();

        try {
            Mail::to($user->email)->send(new IntegrationTestMail($user->company, $user->name));
        } catch (GraphMailException $exception) {
            // Graph failures carry a readable, secret-free reason.
            $mail->recordTest(false);
            Log::warning('Integraciones: falló el correo de prueba.', ['user_id' => $user->id, 'exception' => $exception::class, 'reason' => $exception->reason, 'status' => $exception->status]);

            return back()->with('error', 'No se pudo enviar el correo de prueba: '.$exception->userMessage());
        } catch (Throwable $exception) {
            $mail->recordTest(false);
            Log::warning('Integraciones: falló el correo de prueba.', ['user_id' => $user->id, 'exception' => $exception::class]);

            return back()->with('error', 'No se pudo enviar el correo de prueba. Revisa la configuración de correo del servidor.');
        }

        $mail->recordTest(true);

        return back()->with('success', "Te enviamos un correo de prueba a {$user->email}.");
    }

    public function testTelegram(Request $request, TelegramClient $telegram, TelegramWebhookStatus $webhook): RedirectResponse
    {
        if (! $telegram->isConfigured()) {
            return back()->with('error', 'El bot de Telegram no está configurado.');
        }

        /** @var User $user */
        $user = $request->user();
        $link = $user->telegramLink;

        if ($link === null || ! $link->isLinked()) {
            return back()->with('error', 'Vincula tu Telegram en «Perfil y notificaciones» para recibir el mensaje de prueba.');
        }

        $sent = $telegram->sendMessage((string) $link->chat_id, 'Mensaje de prueba del Gestor TI: el bot funciona y puede escribirte aquí.');
        $webhook->refresh();

        return $sent === null
            ? back()->with('error', 'Telegram no aceptó el mensaje de prueba. Revisa el bot y vuelve a intentar.')
            : back()->with('success', 'Te enviamos un mensaje de prueba por Telegram.');
    }

    public function updateGithub(UpdateGithubIntegrationRequest $request, Settings $settings): RedirectResponse
    {
        $old = $settings->get('github.organization');
        $old = is_string($old) && $old !== '' ? $old : null;
        $new = $request->validated('organization') ?: null;

        if ($new !== $old) {
            $settings->set('github.organization', $new);

            activity('integrations')
                ->causedBy($request->user())
                ->event('github_settings_updated')
                ->withProperties(['old' => ['organization' => $old], 'attributes' => ['organization' => $new]])
                ->log('Cambió la organización de GitHub');
        }

        return back()->with('success', 'Organización de GitHub guardada.');
    }

    /**
     * Sensitive: logged with before/after (`ai_settings_updated`).
     */
    public function updateAi(UpdateAiIntegrationRequest $request, Settings $settings, AiProviderSettings $ai): RedirectResponse
    {
        $old = $ai->snapshot();
        $cap = $request->validated('monthly_cap_usd');
        $cap = $cap === null || (float) $cap <= 0 ? null : round((float) $cap, 2);

        $new = [
            'provider' => (string) $request->validated('provider'),
            'model' => (string) $request->validated('model'),
            'monthly_cap_usd' => $cap,
        ];

        if ($new !== $old) {
            $settings->set('ai.provider', $new['provider']);
            $settings->set('ai.model', $new['model']);
            $settings->set('ai.monthly_cost_cap_usd', $cap ?? 0.0);

            activity('integrations')
                ->causedBy($request->user())
                ->event('ai_settings_updated')
                ->withProperties(['old' => $old, 'attributes' => $new])
                ->log('Cambió el proveedor de IA');
        }

        return back()->with('success', 'Proveedor de IA guardado.');
    }

    private static function microsoftFailure(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof RequestException => 'Microsoft rechazó la conexión (código graph_'.$exception->response->status().').',
            $exception instanceof ConnectionException => 'no se pudo conectar con Microsoft (código graph_connection).',
            default => 'falló la prueba (código graph_failed).',
        };
    }
}
