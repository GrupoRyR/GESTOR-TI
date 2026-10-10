import { useCallback, useSyncExternalStore } from 'react';

/** Live `matchMedia` result (false without a window, e.g. SSR). */
export function useMediaQuery(query: string): boolean {
    const subscribe = useCallback(
        (notify: () => void) => {
            if (typeof window === 'undefined' || !window.matchMedia) return () => undefined;
            const list = window.matchMedia(query);
            list.addEventListener('change', notify);
            return () => list.removeEventListener('change', notify);
        },
        [query],
    );
    const read = () => (typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(query).matches : false);
    return useSyncExternalStore(subscribe, read, () => false);
}
