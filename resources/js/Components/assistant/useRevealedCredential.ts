import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { useClock } from '../../lib/useClock';
import type { RevealedCredential } from '../../types/assistant';
import { REVEAL_SECONDS, revealLeft, startReveal, tickReveal, type ShownSecret } from '../ai/vault/vault';

/** Drop the value from the Inertia page (and so from the history entry) when the router supports it. */
function wipeRevealedProp() {
    const replace = (router as { replaceProp?: (name: string, value: unknown) => void }).replaceProp;
    if (typeof replace === 'function') replace.call(router, 'revealed_credential', null);
}

/**
 * Holds a delivered password in component memory for at most 30 s from the
 * moment it arrives, only when `revealed` belongs to `messageId`. At the
 * deadline or on "Ocultar" the value is dropped and the
 * `revealed_credential` prop wiped; unmounting drops it too. Leaving the page
 * mid-reveal relies on the server's `Inertia::encryptHistory()` and the
 * no-store response. Never log or forward `value`.
 */
export function useRevealedCredential(
    revealed: RevealedCredential | null,
    messageId: number,
): { value: string | null; secondsLeft: number; hide: () => void } {
    const [shown, setShown] = useState<ShownSecret | null>(null);
    const clock = useClock({ running: shown !== null, intervalMs: 250 });

    const hide = useCallback(() => {
        setShown(null);
        wipeRevealedProp();
    }, []);

    useEffect(() => {
        const own = revealed && revealed.message_id === messageId ? revealed : null;
        setShown(own ? startReveal({ item_id: own.message_id, value: own.value, expires_in_seconds: own.expires_in_seconds }, Date.now()) : null);
    }, [revealed, messageId]);

    useEffect(() => {
        if (shown && tickReveal(shown, clock.getTime()) === null) hide();
    }, [clock, shown, hide]);

    return { value: shown?.value ?? null, secondsLeft: Math.min(REVEAL_SECONDS, revealLeft(shown, clock.getTime())), hide };
}
