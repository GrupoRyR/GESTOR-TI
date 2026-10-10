import type { CSSProperties } from 'react';
import { brandStyle, contrastRatio } from '@/lib/brand';
import type { CompanyColors } from '@/types/shared';

const WHITE = '#FFFFFF';
const INK = '#17191C';

const cache = new Map<string, CSSProperties>();

/**
 * Inline style for an element with the `brand-scope` class: both brand sets
 * (`--brand-light-*`, `--brand-dark-*`) so app.css can switch them with the
 * theme, plus the letter-mark colors. Memoized per color triple, because a
 * table in "Todas" renders one scope per row.
 */
export function brandScopeStyle(colors: CompanyColors): CSSProperties {
    const key = `${colors.primary}|${colors.accent}|${colors.dark}`;
    const cached = cache.get(key);
    if (cached) return cached;

    const light = brandStyle(colors, 'light');
    const dark = brandStyle(colors, 'dark');
    const style: Record<string, string> = {};

    for (const [name, value] of Object.entries(light)) {
        style[name.replace('--brand-', '--brand-light-')] = value;
    }
    for (const [name, value] of Object.entries(dark)) {
        style[name.replace('--brand-', '--brand-dark-')] = value;
    }

    // Letter mark: the raw primary in light (with whichever ink reads), the
    // accessible dark button color in dark.
    const primary = light['--brand-primary'] ?? INK;
    style['--brand-light-mark'] = primary;
    style['--brand-light-mark-ink'] = contrastRatio(primary, WHITE) >= 4.5 ? WHITE : INK;
    style['--brand-dark-mark'] = dark['--brand-button'] ?? WHITE;
    style['--brand-dark-mark-ink'] = dark['--brand-button-ink'] ?? INK;

    const result = style as CSSProperties;
    cache.set(key, result);
    return result;
}
