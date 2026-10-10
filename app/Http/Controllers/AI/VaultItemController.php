<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\Vault\ManageVaultItems;
use App\Actions\Ai\Vault\VaultHolders;
use App\Domain\Ai\VaultDueDates;
use App\Http\Controllers\Controller;
use App\Models\AiVaultItem;
use App\Models\User;
use App\Support\Ai\Presenters\VaultPresenter;
use App\Support\Vault\VaultItemForm;
use App\Support\Vault\VaultSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vault item form (C-VaultItemForm, `Ai/Vault/Create` and `Ai/Vault/Edit` of
 * types/pages/ai-value-vault.ts). Behind `vault` (EnsureVaultAccess: holder
 * only, key check, auto-lock, `encryptHistory`, no-store) and, here, an open
 * vault session: without it every action goes to the locked
 * `ai.vault.index` (with `{ item }`) before reading any input.
 *
 * The form never receives the value. Store requires it; an empty value on
 * update keeps the current one. Delete requires typing the exact name.
 * Every change goes through ManageVaultItems (access log create / update /
 * delete + Telegram notice to the holder). Invalid input goes back without
 * ever flashing the value.
 */
class VaultItemController extends Controller
{
    public const LOCKED = 'La bóveda está cerrada. Confirma tu identidad con Microsoft para cambiar credenciales.';

    /** Input never flashed back to the session. */
    private const NEVER_FLASH = ['value', 'confirm_name'];

    public function __construct(
        private readonly ManageVaultItems $items,
        private readonly VaultHolders $holders,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if (! VaultSession::isOpen($request->session())) {
            return $this->locked(null);
        }

        return Inertia::render('Ai/Vault/Create', [
            ...$this->base($request),
            'defaults' => VaultItemForm::defaults($request->query('product'), $request->query('environment')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! VaultSession::isOpen($request->session())) {
            return $this->locked(null);
        }

        try {
            $form = VaultItemForm::validate($request->all(), creating: true);
        } catch (ValidationException $e) {
            return $this->invalid($request, $e);
        }

        $item = $this->items->create($this->user($request), $form['data'], (string) $form['value'], $request);

        return redirect()->route('ai.vault.index', ['item' => $item->id])->with('success', 'Credencial guardada.');
    }

    public function edit(Request $request, AiVaultItem $item): Response|RedirectResponse
    {
        if (! VaultSession::isOpen($request->session())) {
            return $this->locked($item);
        }

        $item->loadMissing(VaultPresenter::RELATIONS);

        return Inertia::render('Ai/Vault/Edit', [
            ...$this->base($request),
            'item' => VaultPresenter::item($item),
            'last_rotated_at' => $item->last_rotated_at?->timezone(VaultDueDates::TIMEZONE)->toDateString(),
        ]);
    }

    public function update(Request $request, AiVaultItem $item): RedirectResponse
    {
        if (! VaultSession::isOpen($request->session())) {
            return $this->locked($item);
        }

        try {
            $form = VaultItemForm::validate($request->all(), creating: false);
        } catch (ValidationException $e) {
            return $this->invalid($request, $e);
        }

        $this->items->update($this->user($request), $item, $form['data'], $form['value'], $request);

        return redirect()->route('ai.vault.index', ['item' => $item->id])->with('success', 'Cambios guardados.');
    }

    public function destroy(Request $request, AiVaultItem $item): RedirectResponse
    {
        if (! VaultSession::isOpen($request->session())) {
            return $this->locked($item);
        }

        try {
            VaultItemForm::confirmDelete($item, $request->input('confirm_name'));
        } catch (ValidationException $e) {
            return $this->invalid($request, $e);
        }

        $this->items->delete($this->user($request), $item, $request);

        return redirect()->route('ai.vault.index')->with('success', 'Credencial eliminada.');
    }

    /**
     * Props shared by Create and Edit (`VaultFormBase` without `errors`).
     *
     * @return array<string, mixed>
     */
    private function base(Request $request): array
    {
        $user = $this->user($request);

        return [
            'session' => VaultSession::state($request->session()),
            'projects' => VaultItemForm::projects(),
            'holder_name' => $this->holders->name(),
            'telegram_alerts' => (bool) $user->routeNotificationForTelegram(),
            'today' => VaultDueDates::today()->toDateString(),
            'now' => now()->toIso8601String(),
        ];
    }

    private function invalid(Request $request, ValidationException $e): RedirectResponse
    {
        return back()
            ->withErrors($e->errors())
            ->withInput($request->except(self::NEVER_FLASH));
    }

    private function locked(?AiVaultItem $item): RedirectResponse
    {
        return redirect()->route('ai.vault.index', $item === null ? [] : ['item' => $item->id])->with('error', self::LOCKED);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
