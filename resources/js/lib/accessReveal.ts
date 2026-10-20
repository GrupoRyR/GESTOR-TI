/**
 * "Ver contraseña" on the employee page (`employees.accesses.reveal`).
 *
 * The only place a page calls the server directly: a POST that answers
 * `{value, expires_in}` as JSON with no-store headers, so the password never
 * enters Inertia props or history. The value lives only in component state
 * and this reducer drops it at the deadline or on "Ocultar". Never log it.
 */

/** What the server sends today (`EmployeeAccessController::REVEAL_SECONDS`). */
export const DEFAULT_REVEAL_SECONDS = 30;

/** Upper bound for an unexpected `expires_in`: never keep a password on screen longer. */
export const MAX_REVEAL_SECONDS = 120;

export type RevealPhase = 'idle' | 'loading' | 'shown' | 'error';

export interface RevealState {
    phase: RevealPhase;
    /** The password; only non-null while `phase === 'shown'`. */
    value: string | null;
    /** Seconds the value stays visible (for the bar). */
    total: number;
    /** Epoch ms when the value hides itself. */
    deadline: number | null;
    secondsLeft: number;
    /** Calm Spanish message after a failed request. */
    error: string | null;
}

export type RevealAction =
    | { type: 'request' }
    | { type: 'success'; value: string; expiresIn: number; now: number }
    | { type: 'failure'; error: string }
    | { type: 'tick'; now: number }
    | { type: 'hide' };

export const INITIAL_REVEAL_STATE: RevealState = {
    phase: 'idle',
    value: null,
    total: DEFAULT_REVEAL_SECONDS,
    deadline: null,
    secondsLeft: 0,
    error: null,
};

/** Whole seconds until `deadline` (epoch ms), rounded up and never negative. */
export function secondsUntil(deadline: number, now: number): number {
    return Math.max(0, Math.ceil((deadline - now) / 1000));
}

function clampSeconds(seconds: number): number {
    if (!Number.isFinite(seconds) || seconds <= 0) return DEFAULT_REVEAL_SECONDS;
    return Math.min(MAX_REVEAL_SECONDS, Math.ceil(seconds));
}

export function revealReducer(state: RevealState, action: RevealAction): RevealState {
    switch (action.type) {
        case 'request':
            return { ...INITIAL_REVEAL_STATE, phase: 'loading' };
        case 'success': {
            const total = clampSeconds(action.expiresIn);
            return {
                phase: 'shown',
                value: action.value,
                total,
                deadline: action.now + total * 1000,
                secondsLeft: total,
                error: null,
            };
        }
        case 'failure':
            return { ...INITIAL_REVEAL_STATE, phase: 'error', error: action.error };
        case 'tick': {
            if (state.phase !== 'shown' || state.deadline === null) return state;
            const left = secondsUntil(state.deadline, action.now);
            if (left === 0) return { ...INITIAL_REVEAL_STATE, total: state.total };
            return left === state.secondsLeft ? state : { ...state, secondsLeft: left };
        }
        case 'hide':
            return { ...INITIAL_REVEAL_STATE, total: state.total };
    }
}

/** `{value, expires_in}` from the endpoint, or `null` when the body is not that. */
export function parseRevealPayload(body: unknown): { value: string; expiresIn: number } | null {
    if (typeof body !== 'object' || body === null) return null;
    const { value, expires_in: expiresIn } = body as { value?: unknown; expires_in?: unknown };
    if (typeof value !== 'string' || value === '') return null;
    return { value, expiresIn: typeof expiresIn === 'number' ? clampSeconds(expiresIn) : DEFAULT_REVEAL_SECONDS };
}

export type RevealFailure = { status: number; message?: string | null } | 'network' | 'invalid';

const FORBIDDEN = 'No tienes permiso para ver esta contraseña.';
const GENERIC = 'No se pudo traer la contraseña. Intenta de nuevo.';

/** Spanish server messages start with "No tienes permiso"; framework defaults are English. */
function isSpanishPermissionMessage(message: string | null | undefined): message is string {
    return typeof message === 'string' && message.startsWith('No tienes permiso');
}

/** Calm inline message for a failed reveal. */
export function revealErrorMessage(failure: RevealFailure): string {
    if (failure === 'network') return 'No hay conexión con el Gestor. Revisa tu red e intenta de nuevo.';
    if (failure === 'invalid') return GENERIC;

    switch (failure.status) {
        case 403:
            return isSpanishPermissionMessage(failure.message) ? failure.message : FORBIDDEN;
        case 429:
            return 'Demasiados intentos. Espera un minuto.';
        case 401:
        case 419:
            return 'Tu sesión expiró. Recarga la página e intenta de nuevo.';
        case 404:
            return 'Este acceso ya no existe. Recarga la página.';
        default:
            return GENERIC;
    }
}

/** Decoded value of cookie `name` in a `document.cookie` string, or `null`. */
export function readCookie(cookies: string, name: string): string | null {
    for (const pair of cookies.split(';')) {
        const index = pair.indexOf('=');
        if (index === -1) continue;
        if (pair.slice(0, index).trim() !== name) continue;
        const raw = pair.slice(index + 1).trim();
        if (raw === '') return null;
        try {
            return decodeURIComponent(raw);
        } catch {
            return raw;
        }
    }
    return null;
}

/**
 * Headers for the reveal POST. Laravel accepts the `XSRF-TOKEN` cookie as
 * `X-XSRF-TOKEN` (always current); the `csrf-token` meta tag is the fallback.
 */
export function revealHeaders({ xsrfCookie, metaToken }: { xsrfCookie: string | null; metaToken: string | null }): Record<string, string> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (xsrfCookie) {
        headers['X-XSRF-TOKEN'] = xsrfCookie;
    } else if (metaToken) {
        headers['X-CSRF-TOKEN'] = metaToken;
    }
    return headers;
}
