<?php

namespace App\Http\Middleware;

use App\Actions\Ai\Vault\RecordVaultAccess;
use App\Actions\Ai\Vault\VaultHolders;
use App\Models\AiVaultItem;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Vault\VaultEncrypter;
use App\Support\Vault\VaultKeyException;
use App\Support\Vault\VaultSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `vault` (PROMPT 6.4.10), after `auth`, on every vault
 * route:
 *
 * 1. Only holders of `ai.vault.access` (granted directly; Súper Admin does
 *    not have it). Anybody else gets `Ai/Vault/Denied`, the attempt is
 *    logged (`denied`) and the holders are alerted.
 * 2. Fails closed when the vault key is missing, invalid or equal to
 *    APP_KEY: back to Inicio with the Spanish reason.
 * 3. Auto-lock: an open vault idle for 10 minutes is locked (logged `lock`,
 *    channel `auto`); otherwise the request refreshes the idle timer.
 * 4. Every vault response is history-encrypted and never cached.
 */
class EnsureVaultAccess
{
    public const NO_STORE = 'no-store, no-cache, private, max-age=0';

    public function __construct(
        private readonly RecordVaultAccess $record,
        private readonly VaultHolders $holders,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        Inertia::encryptHistory();

        if (! VaultHolders::has($user)) {
            return $this->noStore($this->denied($request, $user));
        }

        try {
            VaultEncrypter::assertConfigured();
        } catch (VaultKeyException $e) {
            Log::error('Vault key not usable', ['reason' => $e->getMessage()]);

            return redirect()->route('home')->with('error', $e->getMessage());
        }

        $session = $request->session();

        if (VaultSession::expired($session)) {
            VaultSession::lock($session);
            $this->record->handle('lock', null, $user, $request, 'auto');
        } else {
            VaultSession::touch($session);
        }

        return $this->noStore($next($request));
    }

    private function denied(Request $request, User $user): Response
    {
        $this->record->denied($user, $this->item($request), $request);

        return Inertia::render('Ai/Vault/Denied', [
            'holder_name' => $this->holders->name(),
            'attempt' => ['person' => RequestPresenter::person($user), 'at' => now()->toIso8601String()],
        ])->toResponse($request);
    }

    /**
     * The item of `{item}` or `?item=`, when it exists.
     */
    private function item(Request $request): ?AiVaultItem
    {
        $value = $request->route('item') ?? $request->query('item');

        if ($value instanceof AiVaultItem) {
            return $value;
        }

        return is_numeric($value) ? AiVaultItem::query()->find((int) $value) : null;
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', self::NO_STORE);
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
