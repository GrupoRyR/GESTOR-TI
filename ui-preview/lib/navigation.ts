/**
 * Message the frame posts to the gallery shell when a page tries to navigate
 * (Inertia Link, router.visit, a plain anchor or a form). The frame never
 * leaves the current page; the shell shows "Navegación simulada: <route>".
 */

export const NAVIGATION_MESSAGE = 'gestor-preview:navigate';

export interface NavigationMessage {
    type: typeof NAVIGATION_MESSAGE;
    method: string;
    url: string;
}

export function navigationMessage(method: string, url: string): NavigationMessage {
    return { type: NAVIGATION_MESSAGE, method: method.toLowerCase(), url };
}

export function isNavigationMessage(data: unknown): data is NavigationMessage {
    return (
        typeof data === 'object' &&
        data !== null &&
        (data as { type?: unknown }).type === NAVIGATION_MESSAGE &&
        typeof (data as { url?: unknown }).url === 'string' &&
        typeof (data as { method?: unknown }).method === 'string'
    );
}

/** `#admin.users.index` → `admin.users.index`; non-GET methods are prefixed. */
export function describeNavigation(message: NavigationMessage): string {
    const route = message.url.startsWith('#') ? message.url.slice(1) : message.url;
    return message.method === 'get' ? route : `${message.method.toUpperCase()} ${route}`;
}
