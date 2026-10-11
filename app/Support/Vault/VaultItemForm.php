<?php

namespace App\Support\Vault;

use App\Enums\Ai\ProductStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\AiVaultItem;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rules and choices of the vault item form (C-VaultItemForm,
 * `VaultItemPayload` of types/pages/ai-value-vault.ts).
 *
 * Validated in the controller after the open-session check, so a locked
 * vault never answers with field errors. `value` is never trimmed (TrimStrings
 * excepts it) and never flashed back (Laravel's `dontFlash` + `value`).
 * "Proyecto" is a product or project name: products first, then projects;
 * `AiVaultItem::NO_OWNER` keeps an item without either.
 */
final class VaultItemForm
{
    public const ROTATION_DAYS = [30, 60, 90, 180];

    public const CONFIRM_MESSAGE = 'Escribe el nombre exacto de la credencial para eliminarla.';

    /**
     * Validated payload, with the owner resolved to `ai_product_id` /
     * `ai_project_id`.
     *
     * @param  array<string, mixed>  $input
     * @return array{data: array<string, mixed>, value: ?string}
     *
     * @throws ValidationException
     */
    public static function validate(array $input, bool $creating): array
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:160'],
            'product' => ['required', 'string', 'max:160', self::knownOwner()],
            'environment' => ['required', 'string', Rule::in(AiVaultItem::ENVIRONMENTS)],
            'type' => ['required', 'string', Rule::in(AiVaultItem::TYPES)],
            'username' => ['nullable', 'string', 'max:190'],
            'value' => $creating ? ['required', 'string', 'max:10000'] : ['nullable', 'string', 'max:10000'],
            'rotation_days' => ['nullable', 'integer', Rule::in(self::ROTATION_DAYS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => 'Escribe el nombre de la credencial.',
            'name.max' => 'Usa máximo 160 caracteres.',
            'product.required' => 'Elige un producto o proyecto de la lista.',
            'product.max' => 'Elige un producto o proyecto de la lista.',
            'environment.required' => 'Elige el ambiente.',
            'environment.in' => 'Elige el ambiente.',
            'type.required' => 'Elige el tipo de credencial.',
            'type.in' => 'Elige el tipo de credencial.',
            'username.max' => 'Usa máximo 190 caracteres.',
            'value.required' => 'Escribe el valor de la credencial.',
            'value.max' => 'El valor es demasiado largo.',
            'rotation_days.integer' => 'Elige cada cuánto se rota.',
            'rotation_days.in' => 'Elige cada cuánto se rota.',
            'notes.max' => 'Usa máximo 2.000 caracteres.',
        ])->validate();

        [$productId, $projectId] = self::owner((string) $validated['product']);
        $username = trim((string) ($validated['username'] ?? ''));
        $notes = trim((string) ($validated['notes'] ?? ''));
        $value = $validated['value'] ?? null;

        return [
            'data' => [
                'name' => trim((string) $validated['name']),
                'ai_product_id' => $productId,
                'ai_project_id' => $projectId,
                'environment' => $validated['environment'],
                'type' => $validated['type'],
                'username' => $username === '' ? null : $username,
                'rotate_every_days' => isset($validated['rotation_days']) ? (int) $validated['rotation_days'] : null,
                'notes' => $notes === '' ? null : $notes,
            ],
            'value' => is_string($value) && $value !== '' ? $value : null,
        ];
    }

    /**
     * "Escribe el nombre exacto": spaces around what was typed are ignored
     * (like the page), case is not.
     *
     * @throws ValidationException
     */
    public static function confirmDelete(AiVaultItem $item, mixed $typed): void
    {
        if (! is_string($typed) || $item->name === '' || trim($typed) !== $item->name) {
            throw ValidationException::withMessages(['confirm_name' => self::CONFIRM_MESSAGE]);
        }
    }

    /**
     * "Proyecto" choices: every product in use and every open project, in
     * both environments, plus the pairs of the stored items.
     *
     * @return list<array{product: string, environment: string}>
     */
    public static function projects(): array
    {
        $names = AiProduct::query()->where('status', '!=', ProductStatus::Retired->value)->orderBy('name')->pluck('name')
            ->merge(AiProject::query()->withoutGlobalScopes()->where('status', '!=', RequestStatus::Closed->value)->orderBy('name')->pluck('name'))
            ->unique()
            ->values();

        $options = collect();

        foreach (AiVaultItem::query()->with(['product', 'project'])->orderBy('name')->get() as $item) {
            $options->push(['product' => $item->ownerLabel(), 'environment' => $item->environment]);
        }

        foreach ($names as $name) {
            foreach (AiVaultItem::ENVIRONMENTS as $environment) {
                $options->push(['product' => (string) $name, 'environment' => $environment]);
            }
        }

        return $options->unique(fn (array $option) => $option['product'].'|'.$option['environment'])->values()->all();
    }

    /**
     * `?product=&environment=` when it is one of the choices.
     *
     * @return array{product: string, environment: string}|null
     */
    public static function defaults(mixed $product, mixed $environment): ?array
    {
        if (! is_string($product) || $product === '') {
            return null;
        }

        $environment = in_array($environment, AiVaultItem::ENVIRONMENTS, true) ? $environment : 'production';

        foreach (self::projects() as $option) {
            if ($option['product'] === $product && $option['environment'] === $environment) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?int, 1: ?int} product id, project id
     */
    private static function owner(string $name): array
    {
        if ($name === AiVaultItem::NO_OWNER) {
            return [null, null];
        }

        $product = AiProduct::query()->where('name', $name)->orderBy('id')->first();
        if ($product !== null) {
            return [$product->id, null];
        }

        $project = AiProject::query()->withoutGlobalScopes()->where('name', $name)->orderBy('id')->first();

        return [null, $project?->id];
    }

    private static function knownOwner(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === AiVaultItem::NO_OWNER) {
                return;
            }

            [$product, $project] = self::owner($value);

            if ($product === null && $project === null) {
                $fail('Elige un producto o proyecto de la lista.');
            }
        };
    }
}
