<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateNotificationPreferencesRequest;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Perfil › Notificaciones: channels per event type and the Telegram link.
 */
class NotificationPreferenceController extends Controller
{
    /**
     * Session key holding the deep link right after it is generated (the
     * plain token cannot be recovered later: only its hash is stored).
     */
    public const LINK_URL_SESSION_KEY = 'telegram_link_url';

    public function edit(Request $request, NotificationPreferences $preferences): Response
    {
        /** @var User $user */
        $user = $request->user();
        $link = $user->telegramLink;

        return Inertia::render('Profile/Notifications', [
            'events' => $preferences->forUser($user),
            'telegram' => [
                'linked' => $link !== null && $link->isLinked(),
                'username' => $link?->isLinked() ? $link->telegram_username : null,
                'link_url' => $request->session()->get(self::LINK_URL_SESSION_KEY),
            ],
        ]);
    }

    public function update(UpdateNotificationPreferencesRequest $request, NotificationPreferences $preferences): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $preferences->update($user, $request->preferences());

        return back()->with('success', 'Preferencias de notificación guardadas.');
    }
}
