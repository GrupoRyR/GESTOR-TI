/**
 * Preview stand-in for the one JSON endpoint pages call with `fetch`:
 * `employees.accesses.reveal`. The route shim turns it into
 * `#employees.accesses.reveal`, which no server answers, so this wraps
 * `fetch` and replies with a placeholder. Never a realistic password.
 */
const REVEAL = '#employees.accesses.reveal';

const original = globalThis.fetch?.bind(globalThis);

if (original) {
    globalThis.fetch = (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
        if (url.startsWith(REVEAL) || url.endsWith(REVEAL)) {
            const body = JSON.stringify({ value: '[CONTRASEÑA]', expires_in: 30 });
            const reply = new Response(body, { status: 200, headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' } });
            return new Promise((resolve) => setTimeout(() => resolve(reply), 300));
        }
        return original(input, init);
    };
}

export {};
