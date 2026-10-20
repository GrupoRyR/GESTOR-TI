import { useCallback, useEffect, useReducer, useRef } from 'react';
import { INITIAL_REVEAL_STATE, parseRevealPayload, readCookie, revealErrorMessage, revealHeaders, revealReducer, type RevealState } from '@/lib/accessReveal';

function csrfHeaders(): Record<string, string> {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? null;
    return revealHeaders({ xsrfCookie: readCookie(document.cookie, 'XSRF-TOKEN'), metaToken: meta });
}

async function messageOf(response: Response): Promise<string | null> {
    try {
        const body: unknown = await response.json();
        const message = (body as { message?: unknown } | null)?.message;
        return typeof message === 'string' ? message : null;
    } catch {
        return null;
    }
}

/**
 * "Ver contraseña" for one access: POSTs `url` (`employees.accesses.reveal`)
 * with `fetch`, never through Inertia, so the value stays out of props and
 * history. The value lives only in this hook's state and is dropped at the
 * deadline, on `hide()` and on unmount. Never log it.
 */
export function useAccessReveal(url: string): { state: RevealState; reveal: () => Promise<void>; hide: () => void } {
    const [state, dispatch] = useReducer(revealReducer, INITIAL_REVEAL_STATE);
    const request = useRef<AbortController | null>(null);

    // Unmount: cancel a request in flight (its answer must not land anywhere).
    useEffect(() => () => request.current?.abort(), []);

    // Countdown from the deadline (not from tick counts), so a throttled tab still hides on time.
    useEffect(() => {
        if (state.phase !== 'shown') return;
        const id = window.setInterval(() => dispatch({ type: 'tick', now: Date.now() }), 250);
        return () => window.clearInterval(id);
    }, [state.phase]);

    const reveal = useCallback(async () => {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        dispatch({ type: 'request' });

        let response: Response;
        try {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: csrfHeaders(),
                signal: controller.signal,
            });
        } catch {
            if (!controller.signal.aborted) dispatch({ type: 'failure', error: revealErrorMessage('network') });
            return;
        }
        if (controller.signal.aborted) return;

        if (!response.ok) {
            const message = await messageOf(response);
            if (!controller.signal.aborted) dispatch({ type: 'failure', error: revealErrorMessage({ status: response.status, message }) });
            return;
        }

        let payload: ReturnType<typeof parseRevealPayload> = null;
        try {
            payload = parseRevealPayload(await response.json());
        } catch {
            payload = null;
        }
        if (controller.signal.aborted) return;
        if (payload === null) {
            dispatch({ type: 'failure', error: revealErrorMessage('invalid') });
            return;
        }
        dispatch({ type: 'success', value: payload.value, expiresIn: payload.expiresIn, now: Date.now() });
    }, [url]);

    const hide = useCallback(() => {
        request.current?.abort();
        dispatch({ type: 'hide' });
    }, []);

    return { state, reveal, hide };
}
