<?php

namespace App\Http\Controllers\GitHub;

use App\Domain\GitHub\Events\GitHubEventProcessor;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessGitHubEvent;
use App\Models\GitHubWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GitHub App webhook (`POST /api/github/webhook`, PROMPT §7.2).
 *
 * - 403 while `GITHUB_WEBHOOK_SECRET` is not set; 401 unless
 *   `X-Hub-Signature-256` is the HMAC-SHA256 of the raw body.
 * - `ping` and events the app does not process answer 200 and are not
 *   stored (they would only add noise to the month counters).
 * - Every other delivery is stored once per `X-GitHub-Delivery` (a
 *   redelivery answers 200 `duplicate` without processing it again) and
 *   processed on the database queue (App\Jobs\ProcessGitHubEvent); the
 *   answer is 202 right away so GitHub never waits.
 */
class WebhookController extends Controller
{
    public const SIGNATURE_HEADER = 'X-Hub-Signature-256';

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.github.webhook_secret');
        abort_if($secret === '', 403);
        abort_unless($this->signed($request, $secret), 401);

        $type = (string) $request->header('X-GitHub-Event', '');
        $delivery = (string) $request->header('X-GitHub-Delivery', '');
        abort_if($type === '' || $delivery === '' || strlen($delivery) > 64, 400);

        if ($type === 'ping') {
            return response()->json(['ok' => true]);
        }

        if (! in_array($type, GitHubEventProcessor::HANDLED_EVENTS, true)) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $payload = json_decode($request->getContent(), true);
        abort_unless(is_array($payload), 400);

        if (GitHubWebhookEvent::query()->where('delivery_id', $delivery)->exists()) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            $event = GitHubWebhookEvent::query()->create([
                'delivery_id' => $delivery,
                'event' => $type,
                'action' => isset($payload['action']) ? mb_substr((string) $payload['action'], 0, 40) : null,
                'repository' => isset($payload['repository']['full_name']) ? (string) $payload['repository']['full_name'] : null,
                'payload' => $payload,
                'status' => GitHubWebhookEvent::STATUS_PENDING,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Same delivery received twice at the same time.
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        ProcessGitHubEvent::dispatch($event->id);

        return response()->json(['ok' => true], 202);
    }

    private function signed(Request $request, string $secret): bool
    {
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }
}
