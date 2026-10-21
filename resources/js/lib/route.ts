type RouteParams = Record<string, string | number | boolean | null | undefined> | string | number;

interface ZiggyRouter {
    has: (name: string) => boolean;
}

interface ZiggyRoute {
    (): ZiggyRouter;
    (name: string, params?: RouteParams): string;
}

declare global {
    /** Ziggy's `route()` helper, injected by Laravel's `@routes` Blade directive. */
    // eslint-disable-next-line no-var
    var route: ZiggyRoute | undefined;
}

/**
 * Whether a named route exists. Links to routes the backend may not have
 * shipped yet are rendered only when this is true, because Ziggy throws on
 * unknown names. The backend-free preview treats every route as present.
 */
export function hasRoute(name: string): boolean {
    const ziggy = globalThis.route;
    if (typeof ziggy === 'function') {
        return ziggy().has(name);
    }
    return true;
}

/**
 * Resolve a named Laravel route. In Laravel this delegates to Ziggy; in the
 * backend-free preview (no `@routes`) it returns `#<name>` so links stay inert.
 */
export function route(name: string, params?: RouteParams): string {
    const ziggy = globalThis.route;
    if (typeof ziggy === 'function') {
        return ziggy(name, params);
    }
    return `#${name}`;
}
