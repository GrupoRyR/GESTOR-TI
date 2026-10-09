import { useEffect, useState } from 'react';

/**
 * Ticking "now" for timers and countdowns. `startAt` lets mocks and tests
 * start from a fixed instant (e.g. the mock "today, 9:40 a. m.") and then
 * advance in real time; without it the clock is the device time. Pass
 * `running: false` to freeze it (stopped timer, closed vault).
 */
export function useClock({ startAt, running = true, intervalMs = 1000 }: { startAt?: string; running?: boolean; intervalMs?: number } = {}): Date {
    const [origin] = useState(() => ({ base: startAt ? new Date(startAt).getTime() : Date.now(), mounted: Date.now() }));
    const [now, setNow] = useState(() => new Date(origin.base));

    useEffect(() => {
        if (!running) return;
        const tick = () => setNow(new Date(origin.base + (Date.now() - origin.mounted)));
        tick();
        const id = window.setInterval(tick, intervalMs);
        return () => window.clearInterval(id);
    }, [running, intervalMs, origin]);

    return now;
}
