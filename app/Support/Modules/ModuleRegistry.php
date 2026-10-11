<?php

namespace App\Support\Modules;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Reads the platform modules declared in `config/modules.php`.
 *
 * The configuration is read on every call so that runtime changes
 * (e.g. `config(['modules.ai.active' => false])` in tests) take effect.
 */
class ModuleRegistry
{
    public function __construct(private readonly Config $config) {}

    public function isActive(string $key): bool
    {
        return (bool) ($this->all()[$key]['active'] ?? false);
    }

    /**
     * Modules shared with the given user, in configuration order: the active
     * modules they may see (`active: true`) plus every inactive module
     * (`active: false`, never linked by the menu). Guests see none.
     *
     * The `route` value is a route name (not a URL): the frontend resolves it
     * with Ziggy's `route()`, exposed through the `@routes` Blade directive,
     * and only for active modules.
     *
     * @return list<array{key: string, name: string, icon: string, route: string, active: bool}>
     */
    public function visibleTo(?Authorizable $user): array
    {
        if ($user === null) {
            return [];
        }

        $visible = [];

        foreach ($this->all() as $key => $module) {
            $active = (bool) ($module['active'] ?? false);
            $permission = $module['permission'] ?? null;

            if ($active && $permission !== null && ! $user->can($permission)) {
                continue;
            }

            $visible[] = [
                'key' => $key,
                'name' => $module['name'],
                'icon' => $module['icon'],
                'route' => $module['route'],
                'active' => $active,
            ];
        }

        return $visible;
    }

    /**
     * @return array<string, array{name: string, icon: string, route: string, active: bool, permission: ?string}>
     */
    private function all(): array
    {
        return $this->config->get('modules', []);
    }
}
