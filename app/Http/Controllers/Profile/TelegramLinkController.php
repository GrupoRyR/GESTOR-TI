<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vincular Telegram (`Profile/TelegramLink`), generating a new link and
 * unlinking.
 *
 * Only the SHA-256 of a link token is stored, so the plain deep link
 * `https://t.me/{bot}?start={token}` lives in the session (one entry per
 * user) for the life of the token. The page polls
 * `router.reload({ only: ['status'] })`: `status` is a cheap closure and a
 * reload never issues a new token while the session still holds one.
 */
class TelegramLinkController extends Controller
{
    public const SESSION_PREFIX = 'telegram_link.';

    public const NOT_CONFIGURED = 'El bot de Telegram no está configurado.';

    public function create(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $bot = $this->bot();

        if ($bot === null) {
            return redirect()->route('profile.edit')->with('error', self::NOT_CONFIGURED);
        }

        $pending = $request->session()->get($this->sessionKey($user));

        if (! is_array($pending)) {
            if ($this->isLinked($user)) {
                return redirect()->route('profile.edit');
            }

            $pending = $this->issue($request, $user, $bot);
        }

        return Inertia::render('Profile/TelegramLink', [
            'link_url' => fn (): string => (string) $pending['url'],
            'expires_at' => fn (): string => (string) $pending['expires_at'],
            'status' => fn (): string => $this->status($user, (string) $pending['expires_at']),
        ]);
    }

    /**
     * "Generar otro enlace": replaces the pending token and shows it.
     */
    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $bot = $this->bot();

        if ($bot === null) {
            return redirect()->route('profile.edit')->with('error', self::NOT_CONFIGURED);
        }

        $this->issue($request, $user, $bot);

        return redirect()->route('profile.telegram.create');
    }

    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->telegramLink()->delete();
        $request->session()->forget($this->sessionKey($user));

        return redirect()->route('profile.edit')->with('success', 'Telegram desvinculado.');
    }

    /**
     * @return array{url: string, expires_at: string}
     */
    private function issue(Request $request, User $user, string $bot): array
    {
        $token = TelegramLink::issueToken($user);

        $pending = [
            'url' => 'https://t.me/'.$bot.'?start='.$token,
            'expires_at' => now()->addMinutes(TelegramLink::TOKEN_TTL_MINUTES)->toIso8601String(),
        ];

        $request->session()->put($this->sessionKey($user), $pending);

        return $pending;
    }

    /**
     * `linked` once the bot confirmed the chat, `expired` after the token's
     * life, else `waiting`.
     */
    private function status(User $user, string $expiresAt): string
    {
        if ($this->isLinked($user)) {
            return 'linked';
        }

        return Carbon::parse($expiresAt)->isPast() ? 'expired' : 'waiting';
    }

    private function isLinked(User $user): bool
    {
        return TelegramLink::query()
            ->where('user_id', $user->id)
            ->whereNotNull('chat_id')
            ->whereNotNull('linked_at')
            ->exists();
    }

    private function bot(): ?string
    {
        $bot = ltrim((string) config('services.telegram.bot_username'), '@');

        return $bot === '' || ! filled(config('services.telegram.bot_token')) ? null : $bot;
    }

    private function sessionKey(User $user): string
    {
        return self::SESSION_PREFIX.$user->id;
    }
}
