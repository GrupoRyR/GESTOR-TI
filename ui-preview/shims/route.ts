/**
 * Preview stand-in for Ziggy's global `route()` (Laravel injects the real one
 * through `@routes`). Every named route resolves to an inert `#<name>`.
 */
globalThis.route = (name: string) => `#${name}`;

export {};
