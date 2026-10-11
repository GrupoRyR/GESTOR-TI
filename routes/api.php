<?php

use App\Http\Controllers\GitHub\WebhookController as GitHubWebhookController;
use App\Http\Controllers\Telegram\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (prefix /api, `api` middleware group: stateless, no CSRF)
|--------------------------------------------------------------------------
|
| RESERVED PREFIXES (PROMPT_GESTOR_IA_APP.md §7.3). Not implemented yet and
| must not be used for anything else (covered by tests/Feature/OpenDoorsTest):
|   - /api/local-apps/v1                 heartbeat, report and error of local apps
|                                        (robotcamara…), Bearer token per app
|                                        (`local-app:report`), table monitored_apps.
|   - /api/runner/v1                     local infrastructure agent.
|   - /api/monitors/{provider}/{secret}  external uptime monitor webhooks.
|
*/

// Telegram bot (A7): the secret in the URL is checked with hash_equals in
// the controller (404 on mismatch). Throttled per IP.
Route::post('/telegram/webhook/{secret}', WebhookController::class)
    ->middleware('throttle:telegram-webhook')
    ->name('telegram.webhook');

// GitHub App webhook (D2, PROMPT §7.2): `X-Hub-Signature-256` checked with
// hash_equals in the controller (401 on mismatch, 403 without secret).
// Stored once per `X-GitHub-Delivery` and processed on the queue.
Route::post('/github/webhook', GitHubWebhookController::class)
    ->middleware('throttle:github-webhook')
    ->name('github.webhook');
