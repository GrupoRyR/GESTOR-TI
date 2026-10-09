import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { useClock } from '../../../lib/useClock';
import type { VaultRevealed } from '../../../types/ai';
import { REVEAL_SECONDS, revealLeft, startReveal, tickReveal, type ShownSecret } from './vault';

/** Drop the secret from the Inertia page (and so from history state) when the router supports it. */
function wipeRevealedProp() {
    const replace = (router as { replaceProp?: (name: string, value: unknown) => void }).replaceProp;
    if (typeof replace === 'function') replace.call(router, 'revealed', null);
}

/**
 * Holds a revealed value in component memory for at most 30 s from the
 * moment it arrives. At the deadline or on "Ocultar" the value is dropped
 * and the `revealed` prop wiped from the current page and history entry;
 * when `enabled` turns false (vault locked) or the page unmounts it is
 * dropped from memory. Leaving the page mid-reveal relies on the server's
 * `Inertia::encryptHistory()` for the old history entry. Never log or
 * forward `shown.value`.
 */
export function useRevealedSecret(revealed: VaultRevealed | null, enabled: boolean): { shown: ShownSecret | null; secondsLeft: number; hide: () => void } {
    const [shown, setShown] = useState<ShownSecret | null>(null);
    const clock = useClock({ running: shown !== null, intervalMs: 250 });

    const hide = useCallback(() => {
        setShown(null);
        wipeRevealedProp();
    }, []);

    useEffect(() => {
        setShown(enabled ? startReveal(revealed, Date.now()) : null);
    }, [revealed, enabled]);

    useEffect(() => {
        if (shown && tickReveal(shown, clock.getTime()) === null) hide();
    }, [clock, shown, hide]);

    // The clock may lag one tick behind a fresh reveal; never show more than the full window.
    return { shown, secondsLeft: Math.min(REVEAL_SECONDS, revealLeft(shown, clock.getTime())), hide };
}
