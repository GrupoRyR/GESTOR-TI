/**
 * Preview stand-in for Ziggy's global `route()` (Laravel injects the real one
 * through `@routes`). Every named route resolves to an inert `#<name>`.
 */
globalThis.route = (name: string) => `#${name}`;

// The reveal endpoint is the only `fetch` a page makes; answer it in the preview too.
import './revealFetch';

export {};
