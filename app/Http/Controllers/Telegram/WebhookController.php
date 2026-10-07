<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Telegram\UpdateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telegram bot webhook (`POST /api/telegram/webhook/{secret}`).
 *
 * 404 unless the URL secret matches `TELEGRAM_WEBHOOK_SECRET` (and the
 * `X-Telegram-Bot-Api-Secret-Token` header, when present or required).
 * Answers 200 right away; the update is processed after the response (or
 * on the database queue), so Telegram never waits nor retries.
 */
class WebhookController extends Controller
{
    public const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    public function __invoke(Request $request, string $secret): JsonResponse
    {
        abort_unless($this->authorized($request, $secret), 404);

        $update = $request->json()->all();

        if (is_array($update) && $update !== []) {
            if (config('gestor.telegram.processing') === 'queue') {
                UpdateHandler::dispatch($update);
            } else {
                UpdateHandler::dispatch($update)->afterResponse();
            }
        }

        return response()->json(['ok' => true]);
    }

    private function authorized(Request $request, string $secret): bool
    {
        $expected = (string) config('services.telegram.webhook_secret');

        if ($expected === '' || ! hash_equals($expected, $secret)) {
            return false;
        }

        $header = $request->header(self::SECRET_HEADER);

        if ($header === null || $header === '') {
            return ! config('services.telegram.require_secret_header');
        }

        return hash_equals($expected, (string) $header);
    }
}
