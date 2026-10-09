type RouteParams = Record<string, string | number | boolean | null | undefined> | string | number;

declare global {
    /** Ziggy's `route()` helper, injected by Laravel's `@routes` Blade directive. */
    // eslint-disable-next-line no-var
    var route: ((name: string, params?: RouteParams) => string) | undefined;
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
