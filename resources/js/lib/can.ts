import type { AuthUser, Permission } from '@/types/shared';

/**
 * UI-only permission check. It hides or disables controls; the server is
 * still the authority and re-checks every action.
 */
export function can(user: AuthUser, permission: Permission): boolean {
    return user.permissions.includes(permission);
}
