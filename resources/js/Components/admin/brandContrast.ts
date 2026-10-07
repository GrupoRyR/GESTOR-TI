import { brandStyle, contrastRatio } from '../../lib/brand';
import type { CompanyColors } from '../../types/shared';

/**
 * Live contrast note for the company colors: does white text on the raw
 * primary pass WCAG AA, and which button color the Gestor derives instead.
 */

const WHITE = '#FFFFFF';
const AA = 4.5;

export interface ButtonContrast {
    primary: string;
    /** White on the raw primary. */
    ratio: number;
    passes: boolean;
    /** Light-scheme button color actually used. */
    button: string;
    /** White on that button color. */
    buttonRatio: number;
}

export function buttonContrast(colors: CompanyColors): ButtonContrast {
    const primary = colors.primary.toUpperCase();
    const ratio = contrastRatio(primary, WHITE);
    const button = brandStyle(colors, 'light')['--brand-button'] ?? primary;
    return { primary, ratio, passes: ratio >= AA, button, buttonRatio: contrastRatio(button, WHITE) };
}

/** "4,1:1" */
export function formatRatio(ratio: number): string {
    return `${ratio.toFixed(1).replace('.', ',')}:1`;
}

/** One-line note shown under the color fields. */
export function contrastNote(colors: CompanyColors): string {
    const result = buttonContrast(colors);
    return result.passes
        ? `Blanco sobre el principal pasa AA (${formatRatio(result.ratio)}); los botones usan ${result.button}.`
        : `Blanco sobre el principal no pasa AA; los botones usan ${result.button}.`;
}
