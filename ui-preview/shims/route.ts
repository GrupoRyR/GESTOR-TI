/**
 * Preview stand-in for Ziggy's global `route()` (Laravel injects the real one
 * through `@routes`). Every named route resolves to an inert `#<name>`, and
 * `route().has()` reports every route as present.
 */
function previewRoute(): { has: (name: string) => boolean };
function previewRoute(name: string): string;
function previewRoute(name?: string): string | { has: (name: string) => boolean } {
    return name === undefined ? { has: () => true } : `#${name}`;
}

globalThis.route = previewRoute;

// The reveal endpoint is the only `fetch` a page makes; answer it in the preview too.
import './revealFetch';

export {};
