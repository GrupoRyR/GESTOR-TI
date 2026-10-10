/**
 * Words for Administración › Integraciones › GitHub: the app permissions
 * and the subscribed webhook events, as the page shows them.
 */
import type { GitHubPermission, WebhookEventType } from '../types/github';

const SCOPE_LABEL: Record<string, string> = {
    issues: 'issues',
    pull_requests: 'pull requests',
    contents: 'contenido',
    releases: 'releases',
    actions: 'acciones',
};

function joinSpanish(items: string[]): string {
    if (items.length <= 1) return items.join('');
    return `${items.slice(0, -1).join(', ')} y ${items[items.length - 1]}`;
}

const capitalize = (text: string) => `${text.charAt(0).toUpperCase()}${text.slice(1)}`;

/** "Issues: leer y escribir · Pull requests, contenido, releases y acciones: solo leer". */
export function permissionsSummary(permissions: GitHubPermission[]): string {
    const groups: [GitHubPermission['access'], string][] = [
        ['write', 'leer y escribir'],
        ['read', 'solo leer'],
    ];
    const parts = groups
        .map(([access, words]) => {
            const scopes = permissions.filter((permission) => permission.access === access).map((permission) => SCOPE_LABEL[permission.scope] ?? permission.scope);
            return scopes.length > 0 ? `${capitalize(joinSpanish(scopes))}: ${words}` : null;
        })
        .filter((part): part is string => part !== null);
    return parts.length > 0 ? parts.join(' · ') : 'Sin permisos';
}

/** Event names stay as GitHub calls them, except `push`, which only counts on the main branch. */
export function webhookEventLabel(type: WebhookEventType): string {
    return type === 'push' ? 'push a la rama principal' : type;
}
