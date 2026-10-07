<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Links and unlinks the user's Telegram chat.
 */
class TelegramLinkController extends Controller
{
    /**
     * Generates a single-use token (30 min) and flashes the deep link
     * `https://t.me/{bot}?start={token}`, shown once on the notifications
     * page (`telegram.link_url`).
     */
    public function store(Request $request): RedirectResponse
    {
        $bot = ltrim((string) config('services.telegram.bot_username'), '@');

        if ($bot === '' || ! filled(config('services.telegram.bot_token'))) {
            return back()->with('error', 'El bot de Telegram no está configurado.');
        }

        /** @var User $user */
        $user = $request->user();
        $token = TelegramLink::issueToken($user);

        return back()
            ->with(NotificationPreferenceController::LINK_URL_SESSION_KEY, 'https://t.me/'.$bot.'?start='.$token)
            ->with('success', 'Abre el enlace en Telegram para terminar la vinculación (vence en 30 minutos).');
    }

    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->telegramLink()->delete();

        return back()->with('success', 'Telegram desvinculado.');
    }
}
