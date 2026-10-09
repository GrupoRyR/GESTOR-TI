/**
 * Pure helpers for the Roles y permisos matrix: toggle one cell, count the
 * cells that differ from the saved matrix, and build the PUT payload.
 * Every function returns new arrays; the saved matrix is never mutated.
 */

export interface RoleGrant {
    name: string;
    permissions: string[];
}

export function hasPermission(roles: RoleGrant[], roleName: string, permission: string): boolean {
    return roles.find((role) => role.name === roleName)?.permissions.includes(permission) ?? false;
}

/** Set one cell. Returns the same array when nothing changes. */
export function togglePermission(roles: RoleGrant[], roleName: string, permission: string, on: boolean): RoleGrant[] {
    if (hasPermission(roles, roleName, permission) === on) return roles;
    return roles.map((role) => {
        if (role.name !== roleName) return role;
        const permissions = on ? [...role.permissions, permission] : role.permissions.filter((item) => item !== permission);
        return { ...role, permissions };
    });
}

/** Number of role × permission cells that differ between two matrices. */
export function changedCells(saved: RoleGrant[], current: RoleGrant[]): number {
    const names = new Set([...saved, ...current].map((role) => role.name));
    let count = 0;
    for (const name of names) {
        const before = new Set(saved.find((role) => role.name === name)?.permissions ?? []);
        const after = new Set(current.find((role) => role.name === name)?.permissions ?? []);
        for (const permission of new Set([...before, ...after])) {
            if (before.has(permission) !== after.has(permission)) count += 1;
        }
    }
    return count;
}

/** Body of `admin.roles.update`: every role with its sorted permissions. */
export function rolesPayload(roles: RoleGrant[]): RoleGrant[] {
    return roles.map((role) => ({ name: role.name, permissions: [...role.permissions].sort() }));
}
