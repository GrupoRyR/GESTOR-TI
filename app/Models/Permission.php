<?php

namespace App\Models;

use App\Models\Exceptions\ExclusivePermissionException;
use BackedEnum;
use Spatie\Permission\Models\Permission as SpatiePermission;

use function Illuminate\Support\enum_value;

/**
 * Application permission model (registered in AppServiceProvider) plus the
 * catalog of config/permissions.php.
 *
 * Exclusive permissions (`ai.vault.access`) can never be attached to a role
 * and can only be granted directly inside `withExclusiveGrant()`, which is
 * used exclusively by the dedicated actions in App\Actions\Users.
 */
class Permission extends SpatiePermission
{
    /** Always exclusive, whatever config/permissions.php says. */
    public const VAULT = 'ai.vault.access';

    private static int $exclusiveGrantDepth = 0;

    /**
     * Permission definitions keyed by name, in matrix order.
     *
     * @return array<string, array{label: string, module: string, exclusive: bool}>
     */
    public static function catalog(): array
    {
        return collect(config('permissions.permissions', []))
            ->map(fn (array $definition) => [
                'label' => $definition['label'],
                'module' => $definition['module'],
                'exclusive' => (bool) ($definition['exclusive'] ?? false),
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function exclusiveNames(): array
    {
        $configured = array_keys(array_filter(self::catalog(), fn (array $definition) => $definition['exclusive']));

        return array_values(array_unique([...$configured, self::VAULT]));
    }

    /**
     * Permissions flagged `explicit` in config/permissions.php: grantable
     * through roles, but excluded from the '*' role wildcard.
     *
     * @return list<string>
     */
    public static function explicitNames(): array
    {
        return array_keys(array_filter(
            config('permissions.permissions', []),
            fn (array $definition) => (bool) ($definition['explicit'] ?? false),
        ));
    }

    /**
     * Permissions the '*' role wildcard expands to: every catalog permission
     * except the exclusive and the explicit ones.
     *
     * @return list<string>
     */
    public static function wildcardNames(): array
    {
        return array_values(array_diff(
            array_keys(self::catalog()),
            self::exclusiveNames(),
            self::explicitNames(),
        ));
    }

    public static function isExclusive(string $name): bool
    {
        return in_array($name, self::exclusiveNames(), true);
    }

    /**
     * Run `$callback` allowing exclusive permissions to be granted directly
     * to users. Only for the dedicated, audited actions.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withExclusiveGrant(callable $callback): mixed
    {
        self::$exclusiveGrantDepth++;

        try {
            return $callback();
        } finally {
            self::$exclusiveGrantDepth--;
        }
    }

    public static function exclusiveGrantAllowed(): bool
    {
        return self::$exclusiveGrantDepth > 0;
    }

    /**
     * Names of the exclusive permissions among the given arguments of
     * givePermissionTo()/syncPermissions() (names, enums, models, ids).
     *
     * @return list<string>
     */
    public static function exclusiveAmong(array $permissions): array
    {
        $exclusive = self::exclusiveNames();

        return collect($permissions)
            ->flatten()
            ->filter(fn ($permission) => $permission !== null && $permission !== '')
            ->map(function ($permission) {
                if ($permission instanceof SpatiePermission) {
                    return $permission->name;
                }

                if ($permission instanceof BackedEnum) {
                    return (string) enum_value($permission);
                }

                if (is_int($permission) || (is_string($permission) && ctype_digit($permission))) {
                    return static::query()->whereKey($permission)->value('name');
                }

                return (string) $permission;
            })
            ->filter(fn ($name) => in_array($name, $exclusive, true))
            ->unique()
            ->values()
            ->all();
    }

    public function assignRole(...$roles): static
    {
        $this->guardRoleAssignment($roles);

        return parent::assignRole(...$roles);
    }

    public function syncRoles(...$roles): static
    {
        $this->guardRoleAssignment($roles);

        return parent::syncRoles(...$roles);
    }

    private function guardRoleAssignment(array $roles): void
    {
        if (self::isExclusive($this->name) && collect($roles)->flatten()->filter()->isNotEmpty()) {
            throw ExclusivePermissionException::forRole($this->name);
        }
    }
}
