<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\Vault\RecordVaultAccess;
use App\Actions\Ai\Vault\VaultHolders;
use App\Domain\Ai\VaultDueDates;
use App\Http\Controllers\Controller;
use App\Models\AiVaultAccessLog;
use App\Models\AiVaultItem;
use App\Models\User;
use App\Support\Ai\Presenters\VaultPresenter;
use App\Support\Vault\VaultKeyException;
use App\Support\Vault\VaultSession;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Credentials vault (PROMPT 6.4.10, `Ai/Vault/Index` of
 * types/pages/ai-value-vault.ts). Every route runs behind `vault`
 * (App\Http\Middleware\EnsureVaultAccess: holder only, key check, auto-lock,
 * encrypted history, no-store).
 *
 * - index: metadata of every item, the `?item=` selection and, while the
 *   vault is open, its access log. `revealed` is always null here.
 * - unlock (`ai.vault.reauth`, behind `reauth:10`): a fresh Microsoft
 *   sign-in opens the vault for this session (VaultSession).
 * - reveal: the only response that carries a value, once, as
 *   `revealed {item_id, value, expires_in_seconds: 30}`; logged and noticed.
 * - copied: logs the copy and notices it; the value never travels back.
 * - lock: closes the session.
 */
class VaultController extends Controller
{
    public const REVEAL_SECONDS = 30;

    public const LOCKED = 'La bóveda está cerrada. Confirma tu identidad con Microsoft para ver el valor.';

    private const LOG_LIMIT = 20;

    public function __construct(
        private readonly RecordVaultAccess $record,
        private readonly VaultHolders $holders,
    ) {}

    public function index(Request $request): Response
    {
        return $this->page($request, $this->selectedId($request), null)->toResponse($request);
    }

    public function unlock(Request $request): RedirectResponse
    {
        VaultSession::open($request->session());
        $this->record->handle('unlock', null, $this->user($request), $request);

        $item = $this->selectedId($request);

        return redirect()->route('ai.vault.index', $item === null ? [] : ['item' => $item]);
    }

    public function lock(Request $request): RedirectResponse
    {
        VaultSession::lock($request->session());
        $this->record->handle('lock', null, $this->user($request), $request);

        return redirect()->route('ai.vault.index');
    }

    public function reveal(Request $request, AiVaultItem $item): Response
    {
        if (! VaultSession::isOpen($request->session())) {
            return $this->closed($item);
        }

        try {
            $value = $item->revealSecret();
        } catch (VaultKeyException|DecryptException $e) {
            Log::error('Vault value could not be decrypted', ['item_id' => $item->id, 'error' => $e::class]);

            return redirect()->route('ai.vault.index', ['item' => $item->id])
                ->with('error', 'No se pudo descifrar el valor: la llave de la bóveda (VAULT_KEY) no corresponde a la que lo guardó.');
        }

        $this->record->handle('view', $item, $this->user($request), $request);

        $revealed = ['item_id' => $item->id, 'value' => $value, 'expires_in_seconds' => self::REVEAL_SECONDS];
        unset($value);

        return $this->page($request, $item->id, $revealed)->toResponse($request);
    }

    public function copied(Request $request, AiVaultItem $item): RedirectResponse
    {
        if (! VaultSession::isOpen($request->session())) {
            return $this->closed($item);
        }

        $this->record->handle('copy', $item, $this->user($request), $request);

        return redirect()->route('ai.vault.index', ['item' => $item->id]);
    }

    /**
     * @param  array{item_id: int, value: string, expires_in_seconds: int}|null  $revealed
     */
    private function page(Request $request, ?int $selectedId, ?array $revealed): InertiaResponse
    {
        $user = $this->user($request);
        $session = $request->session();
        $items = fn (): Collection => AiVaultItem::query()->with(VaultPresenter::RELATIONS)->orderBy('name')->orderBy('id')->get();
        $selected = fn (): ?AiVaultItem => $selectedId === null ? null : AiVaultItem::query()->with(VaultPresenter::RELATIONS)->find($selectedId);

        return Inertia::render('Ai/Vault/Index', [
            'session' => fn () => VaultSession::state($session),
            'items' => fn () => $items()->map(fn (AiVaultItem $item) => VaultPresenter::item($item))->values()->all(),
            'selected' => function () use ($selected): ?array {
                $item = $selected();

                return $item === null ? null : VaultPresenter::item($item);
            },
            'revealed' => fn () => $revealed,
            'access_log' => fn () => $this->accessLog($session, $selectedId),
            'telegram_alerts' => fn () => (bool) $user->routeNotificationForTelegram(),
            'holder_name' => fn () => $this->holders->name(),
            'today' => fn () => VaultDueDates::today()->toDateString(),
            'now' => fn () => now()->toIso8601String(),
        ]);
    }

    /**
     * Accesses of the selected item, newest first; empty while locked.
     *
     * @return list<array<string, mixed>>
     */
    private function accessLog(Session $session, ?int $selectedId): array
    {
        if ($selectedId === null || ! VaultSession::isOpen($session)) {
            return [];
        }

        return AiVaultAccessLog::query()
            ->where('ai_vault_item_id', $selectedId)
            ->whereIn('action', array_keys(AiVaultAccessLog::DESIGN_ACTIONS))
            ->with(['user.employee', 'item'])
            ->orderByDesc('id')
            ->limit(self::LOG_LIMIT)
            ->get()
            ->map(fn (AiVaultAccessLog $log) => VaultPresenter::log($log))
            ->values()
            ->all();
    }

    private function closed(AiVaultItem $item): RedirectResponse
    {
        return redirect()->route('ai.vault.index', ['item' => $item->id])->with('error', self::LOCKED);
    }

    private function selectedId(Request $request): ?int
    {
        $item = $request->query('item');

        return is_numeric($item) && AiVaultItem::query()->whereKey((int) $item)->exists() ? (int) $item : null;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
