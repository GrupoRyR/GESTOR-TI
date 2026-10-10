<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGitHubEvent;
use App\Models\GitHubWebhookEvent;
use App\Services\GitHub\GitHubApp;
use App\Support\Integrations\GitHubIntegration;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración › Integraciones › GitHub (`Admin/Integrations/GitHub`).
 * Route-gated by `ai.github.manage` (Líder IA has it without
 * `settings.manage`); the organization itself is saved from the
 * Integraciones card (`settings.manage`). Never calls GitHub while
 * rendering; secrets only leave as booleans.
 */
class GitHubIntegrationController extends Controller
{
    public function show(GitHubIntegration $github): Response
    {
        return Inertia::render('Admin/Integrations/GitHub', $github->toPageProps());
    }

    /**
     * "Reprocesar": a failed event goes back to `pending` on the queue.
     */
    public function retry(Request $request, GitHubWebhookEvent $event): RedirectResponse
    {
        if (! $event->isFailed()) {
            return back()->with('error', 'Este evento ya no está fallido.');
        }

        $previous = ['error_code' => $event->error_code, 'attempts' => $event->attempts];
        $event->forceFill(['status' => GitHubWebhookEvent::STATUS_PENDING, 'error' => null, 'error_code' => null])->save();

        activity('integrations')
            ->causedBy($request->user())
            ->performedOn($event)
            ->event('github_event_retried')
            ->withProperties(['old' => $previous, 'attributes' => ['event' => $event->event, 'repository' => $event->repository]])
            ->log('Reprocesó un evento de GitHub');

        ProcessGitHubEvent::dispatch($event->id);

        return back()->with('success', 'Evento enviado a reprocesar.');
    }

    /**
     * "Desconectar GitHub": forgets the installation (and its cached token).
     * Stored events and product/project links are kept. Sensitive.
     */
    public function disconnect(Request $request, Settings $settings, GitHubApp $client): RedirectResponse
    {
        $installationId = $client->installationId();

        if ($installationId !== null) {
            $client->forgetInstallationToken($installationId);
            $settings->forget('github.installation_id');

            activity('integrations')
                ->causedBy($request->user())
                ->event('github_disconnected')
                ->withProperties(['old' => ['installation_id' => $installationId], 'attributes' => ['installation_id' => null]])
                ->log('Desconectó GitHub');
        }

        return back()->with('success', 'GitHub quedó desconectado. Los eventos y los vínculos se conservan.');
    }
}
