import { describe, expect, it } from 'vitest';
import {
    DEFAULT_REVEAL_SECONDS,
    INITIAL_REVEAL_STATE,
    MAX_REVEAL_SECONDS,
    parseRevealPayload,
    readCookie,
    revealErrorMessage,
    revealHeaders,
    revealReducer,
    secondsUntil,
    type RevealState,
} from './accessReveal';

const T0 = 1_000_000;

function shown(now = T0, expiresIn = 30): RevealState {
    return revealReducer(revealReducer(INITIAL_REVEAL_STATE, { type: 'request' }), {
        type: 'success',
        value: '[CONTRASEÑA]',
        expiresIn,
        now,
    });
}

describe('secondsUntil', () => {
    it('rounds up and never goes below zero', () => {
        expect(secondsUntil(T0 + 30_000, T0)).toBe(30);
        expect(secondsUntil(T0 + 29_001, T0)).toBe(30);
        expect(secondsUntil(T0 + 29_000, T0)).toBe(29);
        expect(secondsUntil(T0, T0)).toBe(0);
        expect(secondsUntil(T0 - 5_000, T0)).toBe(0);
    });
});

describe('revealReducer', () => {
    it('starts idle with no value', () => {
        expect(INITIAL_REVEAL_STATE).toMatchObject({ phase: 'idle', value: null, error: null, deadline: null });
    });

    it('goes to loading on request and clears a previous error', () => {
        const failed = revealReducer(INITIAL_REVEAL_STATE, { type: 'failure', error: 'x' });
        const next = revealReducer(failed, { type: 'request' });
        expect(next).toMatchObject({ phase: 'loading', value: null, error: null });
    });

    it('shows the value with a deadline of expires_in seconds', () => {
        const state = shown();
        expect(state).toMatchObject({ phase: 'shown', value: '[CONTRASEÑA]', total: 30, secondsLeft: 30, deadline: T0 + 30_000 });
    });

    it('counts down on tick', () => {
        const state = revealReducer(shown(), { type: 'tick', now: T0 + 12_400 });
        expect(state.phase).toBe('shown');
        expect(state.secondsLeft).toBe(18);
        expect(state.value).toBe('[CONTRASEÑA]');
    });

    it('drops the value when the deadline passes', () => {
        const state = revealReducer(shown(), { type: 'tick', now: T0 + 30_000 });
        expect(state).toMatchObject({ phase: 'idle', value: null, deadline: null, secondsLeft: 0 });
    });

    it('drops the value on hide', () => {
        const state = revealReducer(shown(), { type: 'hide' });
        expect(state).toMatchObject({ phase: 'idle', value: null, deadline: null });
    });

    it('ignores ticks when nothing is shown', () => {
        expect(revealReducer(INITIAL_REVEAL_STATE, { type: 'tick', now: T0 })).toBe(INITIAL_REVEAL_STATE);
    });

    it('keeps the error message and no value on failure', () => {
        const state = revealReducer(revealReducer(INITIAL_REVEAL_STATE, { type: 'request' }), { type: 'failure', error: 'Demasiados intentos. Espera un minuto.' });
        expect(state).toMatchObject({ phase: 'error', value: null, error: 'Demasiados intentos. Espera un minuto.' });
    });

    it('clamps an odd expires_in to a sane window', () => {
        expect(shown(T0, 0).total).toBe(DEFAULT_REVEAL_SECONDS);
        expect(shown(T0, -4).total).toBe(DEFAULT_REVEAL_SECONDS);
        expect(shown(T0, 9999).total).toBe(MAX_REVEAL_SECONDS);
    });
});

describe('parseRevealPayload', () => {
    it('reads value and expires_in', () => {
        expect(parseRevealPayload({ value: 'abc', expires_in: 30 })).toEqual({ value: 'abc', expiresIn: 30 });
    });

    it('defaults expires_in when missing or invalid', () => {
        expect(parseRevealPayload({ value: 'abc' })).toEqual({ value: 'abc', expiresIn: DEFAULT_REVEAL_SECONDS });
        expect(parseRevealPayload({ value: 'abc', expires_in: 'soon' })).toEqual({ value: 'abc', expiresIn: DEFAULT_REVEAL_SECONDS });
    });

    it('rejects anything without a string value', () => {
        expect(parseRevealPayload(null)).toBeNull();
        expect(parseRevealPayload('abc')).toBeNull();
        expect(parseRevealPayload({ value: 42 })).toBeNull();
        expect(parseRevealPayload({ value: '' })).toBeNull();
    });
});

describe('revealErrorMessage', () => {
    it('uses the Spanish server message on 403', () => {
        expect(revealErrorMessage({ status: 403, message: 'No tienes permiso para ver esta contraseña.' })).toBe(
            'No tienes permiso para ver esta contraseña.',
        );
    });

    it('falls back to a default 403 message', () => {
        expect(revealErrorMessage({ status: 403 })).toBe('No tienes permiso para ver esta contraseña.');
        expect(revealErrorMessage({ status: 403, message: 'This action is unauthorized.' })).toBe('No tienes permiso para ver esta contraseña.');
    });

    it('asks to wait on 429', () => {
        expect(revealErrorMessage({ status: 429, message: 'Too Many Attempts.' })).toBe('Demasiados intentos. Espera un minuto.');
    });

    it('asks to reload on an expired session', () => {
        expect(revealErrorMessage({ status: 419 })).toBe('Tu sesión expiró. Recarga la página e intenta de nuevo.');
        expect(revealErrorMessage({ status: 401 })).toBe('Tu sesión expiró. Recarga la página e intenta de nuevo.');
    });

    it('explains a missing access', () => {
        expect(revealErrorMessage({ status: 404 })).toBe('Este acceso ya no existe. Recarga la página.');
    });

    it('has calm messages for network and unexpected failures', () => {
        expect(revealErrorMessage('network')).toBe('No hay conexión con el Gestor. Revisa tu red e intenta de nuevo.');
        expect(revealErrorMessage({ status: 500 })).toBe('No se pudo traer la contraseña. Intenta de nuevo.');
        expect(revealErrorMessage('invalid')).toBe('No se pudo traer la contraseña. Intenta de nuevo.');
    });
});

describe('readCookie', () => {
    it('finds and decodes a cookie by name', () => {
        expect(readCookie('a=1; XSRF-TOKEN=ey%3D%3Dabc; b=2', 'XSRF-TOKEN')).toBe('ey==abc');
    });

    it('returns null when absent or empty', () => {
        expect(readCookie('', 'XSRF-TOKEN')).toBeNull();
        expect(readCookie('xXSRF-TOKEN=1', 'XSRF-TOKEN')).toBeNull();
        expect(readCookie('XSRF-TOKEN=', 'XSRF-TOKEN')).toBeNull();
    });
});

describe('revealHeaders', () => {
    it('sends JSON accept and the XSRF cookie when present', () => {
        expect(revealHeaders({ xsrfCookie: 'tok', metaToken: 'meta' })).toEqual({
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': 'tok',
        });
    });

    it('falls back to the csrf meta token', () => {
        expect(revealHeaders({ xsrfCookie: null, metaToken: 'meta' })).toEqual({
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': 'meta',
        });
    });

    it('sends no token when none is available', () => {
        expect(revealHeaders({ xsrfCookie: null, metaToken: null })).toEqual({
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        });
    });
});
