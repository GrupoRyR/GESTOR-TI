<?php

namespace App\Console\Commands;

use App\Domain\Ai\FirstResponse;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\Scopes\CompanyScope;
use App\Services\Alerts\AlertService;
use Illuminate\Console\Command;

/**
 * Requests past their first-response deadline without an answer raise a
 * `warning` alert (source `ai`) to the `ai.manage` holders of the request's
 * company plus global users. One alert per request (group key
 * `ai.first_response:{key}`): later runs update it and AlertService's
 * cooldown decides when it is notified again; the first response resolves it.
 */
class FirstResponseAlertsCommand extends Command
{
    protected $signature = 'ai:first-response-alerts';

    protected $description = 'Avisa las solicitudes del Gestor IA sin primera respuesta a tiempo';

    public function handle(AlertService $alerts): int
    {
        $count = 0;

        AiRequest::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with('company')
            ->whereNull('first_responded_at')
            ->whereNotNull('first_response_due_at')
            ->where('first_response_due_at', '<=', now())
            ->whereNotIn('status', RequestStatus::finishedValues())
            ->chunkById(100, function ($requests) use ($alerts, &$count): void {
                foreach ($requests as $request) {
                    /** @var AiRequest $request */
                    $alerts->raise(
                        level: 'warning',
                        source: 'ai',
                        title: "{$request->key} sin primera respuesta",
                        body: "«{$request->title}» venció el plazo de primera respuesta el {$request->first_response_due_at?->timezone('America/Bogota')->format('d/m/Y g:i a')}.",
                        company: $request->company,
                        subject: $request,
                        groupKey: FirstResponse::alertGroup($request),
                    );
                    $count++;
                }
            });

        $this->info("Solicitudes vencidas: {$count}.");

        return self::SUCCESS;
    }
}
