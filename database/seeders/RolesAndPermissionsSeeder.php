<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds permissions and roles from config/permissions.php. Idempotent:
 * permissions are created when missing and each role is synced to its
 * definition (this resets changes made through the matrix). Permissions
 * removed from the config are not deleted.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $catalog = Permission::catalog();

        foreach (array_keys($catalog) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Model events (which refresh the cache) are muted under
        // DatabaseSeeder's WithoutModelEvents: reload explicitly.
        $registrar->forgetCachedPermissions();

        // '*' = every permission except the exclusive (vault) and the
        // explicit ones (`ai.projects.approve`), which a role must list.
        $wildcard = Permission::wildcardNames();

        foreach (config('permissions.roles', []) as $roleName => $permissions) {
            /** @var Role $role */
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions === '*' ? $wildcard : $permissions);
        }

        $registrar->forgetCachedPermissions();
    }
}
