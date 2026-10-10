import type { StatusFamily } from '@/lib/status';

/** Join class names, skipping falsy values. */
export function cx(...classes: (string | false | null | undefined)[]): string {
    return classes.filter(Boolean).join(' ');
}

/**
 * Tailwind classes for each status family. Literal strings so Tailwind can
 * see them. gray = neutral · amber = maintenance · blue = scheduled ·
 * green = active · slate = local · red = failure.
 */
export const FAMILY_TONE: Record<StatusFamily, { text: string; bg: string; fill: string; dot: string }> = {
    gray: { text: 'text-status-neutral', bg: 'bg-status-neutral-bg', fill: 'bg-status-neutral', dot: 'bg-status-neutral' },
    amber: {
        text: 'text-status-maintenance',
        bg: 'bg-status-maintenance-bg',
        fill: 'bg-status-maintenance',
        dot: 'bg-status-maintenance-dot',
    },
    blue: { text: 'text-status-scheduled', bg: 'bg-status-scheduled-bg', fill: 'bg-status-scheduled', dot: 'bg-status-scheduled' },
    green: { text: 'text-status-active', bg: 'bg-status-active-bg', fill: 'bg-status-active', dot: 'bg-status-active-dot' },
    slate: { text: 'text-status-local', bg: 'bg-status-local-bg', fill: 'bg-status-local', dot: 'bg-status-local' },
    red: { text: 'text-status-failure', bg: 'bg-status-failure-bg', fill: 'bg-status-failure', dot: 'bg-status-failure' },
};

/** "Jhon Castro" → "JC". */
export function initials(name: string): string {
    const words = name.trim().split(/\s+/).filter(Boolean);
    const letters = words.length > 1 ? [words[0], words[words.length - 1]] : words;
    return letters
        .map((word) => word?.charAt(0) ?? '')
        .join('')
        .toUpperCase();
}
