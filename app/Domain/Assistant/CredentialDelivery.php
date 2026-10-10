<?php

namespace App\Domain\Assistant;

use App\Models\AiVaultItem;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Employee;
use App\Models\EmployeeAccess;
use App\Models\User;
use App\Services\Credentials\EmployeeCredentialService;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Password delivery of the Asistente TI (PROMPT 6.8.2, 6.8.4). The model
 * only says which credential and whose; the Gestor TI answers the person
 * directly in the chat and the model hears only the outcome:
 *
 * - without `assistant.credentials` AND `credentials.view`: a `refused`
 *   message ("No puedo entregar contraseñas. Pídela a TI.");
 * - employee accesses are found by EmployeeCredentialService::findForDelivery()
 *   (no password column): one person → a `credential_notice` (system
 *   message with whose, which system and the user name, NEVER the value);
 *   several → a `person_choice` to pick from; none → "no encontrada";
 * - nothing among the accesses but a vault item with that name → a
 *   `vault_link` (the vault is never delivered here);
 * - asset credentials (inventory) arrive with the inventory module: until
 *   then an asset is "no encontrada".
 *
 * The value is read only on "Mostrar" (App\Http\Controllers\Assistant\CredentialController
 * through EmployeeCredentialService::reveal()). Every delivery is logged
 * (`credentials`, `credential_delivered`).
 */
final class CredentialDelivery
{
    public const REFUSED = 'No puedo entregar contraseñas. Pídela a TI.';

    public const VAULT = 'Esa credencial está en la bóveda del proyecto. Ábrela ahí; te pedirá confirmar tu identidad.';

    public const NONE = 'Entendido, no entregué ninguna contraseña.';

    private const COUNT_WORDS = [2 => 'dos', 3 => 'tres', 4 => 'cuatro', 5 => 'cinco'];

    /** Words that never identify a vault item on their own. */
    private const VAULT_STOPWORDS = ['api', 'key', 'llave', 'clave', 'contrasena', 'password', 'del', 'las', 'los', 'para', 'token', 'acceso', 'usuario'];

    public function __construct(
        private readonly EmployeeCredentialService $credentials,
        private readonly CurrentCompany $companies,
    ) {}

    public static function allowed(User $user): bool
    {
        return $user->can('assistant.credentials') && $user->can(EmployeeCredentialService::PERMISSION);
    }

    /**
     * @return array{0: 'refused'|'delivered'|'choose'|'vault'|'not_found', 1: ?AssistantMessage}
     */
    public function request(User $user, AssistantConversation $conversation, string $holder, string $system, string $kind = 'colaborador'): array
    {
        if (! self::allowed($user)) {
            return ['refused', $this->message($conversation, 'assistant', 'refused', self::REFUSED)];
        }

        $candidates = $kind === 'activo' ? collect() : $this->candidates($user, $holder, $system);

        if ($candidates->count() === 1) {
            return ['delivered', $this->deliver($user, $conversation, $candidates->first())];
        }

        if ($candidates->count() > 1) {
            $count = $candidates->count();

            return ['choose', $this->message($conversation, 'assistant', 'person_choice',
                'Hay '.(self::COUNT_WORDS[$count] ?? $count).' personas con ese nombre. ¿De cuál?',
                [
                    'system' => $candidates->first()->system?->name,
                    'options' => $candidates->map(fn (EmployeeAccess $access) => ['employee_id' => $access->employee_id, 'access_id' => $access->id])->values()->all(),
                    'resolved' => false,
                    'resolved_option_id' => null,
                ],
            )];
        }

        $item = $this->vaultItem($holder.' '.$system);
        if ($item !== null) {
            return ['vault', $this->message($conversation, 'assistant', 'vault_link', self::VAULT, ['item_id' => $item->id])];
        }

        return ['not_found', null];
    }

    /**
     * "Juan Pérez · Asesor comercial · Ventas · Segurex" (or "Ninguno") on a
     * person choice; answered once.
     *
     * @return AssistantMessage|null the last message written, null when already answered or not an option
     */
    public function pick(User $user, AssistantMessage $choice, ?int $employeeId): ?AssistantMessage
    {
        if ($choice->data('resolved') === true) {
            return null;
        }

        $options = collect((array) $choice->data('options', []));
        $option = $employeeId === null ? null : $options->firstWhere('employee_id', $employeeId);
        if ($employeeId !== null && $option === null) {
            return null;
        }

        $choice->forceFill(['payload' => [...($choice->payload ?? []), 'resolved' => true, 'resolved_option_id' => $employeeId]])->save();
        $conversation = $choice->conversation;

        if ($option === null) {
            $this->message($conversation, 'user', 'text', $options->count() === 2 ? 'Ninguno de los dos' : 'Ninguno');

            return $this->message($conversation, 'assistant', 'answer', self::NONE, ['sources' => []]);
        }

        $access = EmployeeAccess::query()
            ->select(['id', 'employee_id', 'access_system_id', 'username'])
            ->with(['employee' => fn ($query) => $query->withoutGlobalScopes()->with(['area' => fn ($area) => $area->withoutGlobalScopes(), 'company']), 'system'])
            ->find((int) $option['access_id']);

        if ($access === null || $access->employee === null) {
            return $this->message($conversation, 'assistant', 'answer', 'Ese acceso ya no existe.', ['sources' => []]);
        }

        $this->message($conversation, 'user', 'text', self::personLine($access->employee));

        if (! self::allowed($user) || ! $this->mayReveal($user, $access->employee)) {
            return $this->message($conversation, 'assistant', 'refused', self::REFUSED);
        }

        return $this->deliver($user, $conversation, $access);
    }

    public static function personLine(Employee $employee): string
    {
        return implode(' · ', array_filter([
            $employee->name,
            $employee->job_title,
            $employee->area?->name,
            $employee->company?->short_name ?: $employee->company?->name,
        ], fn (?string $part) => filled($part)));
    }

    /**
     * One access per person (the first match of the system).
     *
     * @return Collection<int, EmployeeAccess>
     */
    private function candidates(User $user, string $holder, string $system): Collection
    {
        return $this->credentials->findForDelivery($holder, $system)
            ->filter(fn (EmployeeAccess $access) => $access->employee !== null && $this->mayReveal($user, $access->employee))
            ->unique('employee_id')
            ->values();
    }

    private function mayReveal(User $user, Employee $employee): bool
    {
        return $this->companies->canViewAll($user) || $employee->company_id === $user->company_id;
    }

    private function deliver(User $user, AssistantConversation $conversation, EmployeeAccess $access): AssistantMessage
    {
        $access->loadMissing(['employee' => fn ($query) => $query->withoutGlobalScopes(), 'system']);

        activity('credentials')
            ->causedBy($user)
            ->performedOn($access)
            ->event('credential_delivered')
            ->withProperties([
                'channel' => $conversation->channel === 'telegram' ? 'telegram' : 'assistant',
                'employee_id' => $access->employee_id,
                'employee_name' => $access->employee?->name,
                'access_system_id' => $access->access_system_id,
                'access_system' => $access->system?->name,
                'username' => $access->username,
            ])
            ->log('credential_delivered');

        return $this->message($conversation, 'system', 'credential_notice', null, [
            'access_id' => $access->id,
            'employee_id' => $access->employee_id,
            'system' => $access->system?->name,
            'username' => $access->username,
        ]);
    }

    private function vaultItem(string $text): ?AiVaultItem
    {
        $words = array_values(array_diff(
            array_filter(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text))) ?: [], fn (string $word) => strlen($word) >= 3),
            self::VAULT_STOPWORDS,
        ));

        if ($words === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach (AiVaultItem::query()->orderBy('name')->get(['id', 'name', 'ai_product_id', 'environment']) as $item) {
            $name = ' '.trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($item->name)))).' ';
            $score = count(array_filter($words, fn (string $word) => str_contains($name, ' '.$word.' ')));

            if ($score > $bestScore) {
                [$best, $bestScore] = [$item, $score];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function message(AssistantConversation $conversation, string $role, string $kind, ?string $body, ?array $payload = null): AssistantMessage
    {
        $message = $conversation->messages()->create(['role' => $role, 'kind' => $kind, 'body' => $body, 'payload' => $payload]);
        $conversation->forceFill(['last_activity_at' => now()])->save();

        return $message;
    }
}
