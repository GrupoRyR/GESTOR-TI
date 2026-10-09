<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateNotificationChannelRequest;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Perfil (`Profile/Edit`): account data, Telegram state and the event ×
 * channel matrix. Any signed-in user; no extra permission.
 */
class ProfileController extends Controller
{
    /** `profile.company_name` for users without a company (emergency admin). */
    public const NO_COMPANY = 'Sin empresa';

    public function edit(Request $request, NotificationPreferences $preferences): Response
    {
        /** @var User $user */
        $user = $request->user();
        // The user's own employee record and area, whatever company is
        // selected in the top bar (CompanyScope would hide them in another one).
        $user->load([
            'company:id,name',
            'employee' => fn ($query) => $query->withoutGlobalScopes(),
            'employee.area' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'name']),
            'telegramLink',
        ]);

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'company_name' => $user->company?->name ?? self::NO_COMPANY,
                'area_name' => $user->employee?->area?->name,
                'roles' => $user->getRoleNames()->values()->all(),
            ],
            'telegram' => $this->telegramState($user),
            'notification_events' => $preferences->matrixFor($user),
        ]);
    }

    /**
     * Saves one checkbox (auto-save, no flash: the page announces it).
     */
    public function updateNotification(UpdateNotificationChannelRequest $request, NotificationPreferences $preferences): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $preferences->toggle(
            $user,
            (string) $request->validated('event'),
            (string) $request->validated('channel'),
            $request->boolean('enabled'),
        );

        return back();
    }

    /**
     * @return array{linked: true, username: string, linked_at: string}|array{linked: false}
     */
    private function telegramState(User $user): array
    {
        $link = $user->telegramLink;

        if ($link === null || ! $link->isLinked()) {
            return ['linked' => false];
        }

        return [
            'linked' => true,
            'username' => (string) $link->telegram_username,
            'linked_at' => $link->linked_at->toIso8601String(),
        ];
    }
}
