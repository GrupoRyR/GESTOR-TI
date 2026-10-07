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
     * Active modules the given user may see, in configuration order.
     * Guests see none.
     *
     * The `route` value is a route name (not a URL): the frontend resolves it
     * with Ziggy's `route()`, exposed through the `@routes` Blade directive.
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
            if (! ($module['active'] ?? false)) {
                continue;
            }

            $permission = $module['permission'] ?? null;

            if ($permission !== null && ! $user->can($permission)) {
                continue;
            }

            $visible[] = [
                'key' => $key,
                'name' => $module['name'],
                'icon' => $module['icon'],
                'route' => $module['route'],
                'active' => true,
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
