import type { Company } from '@/types/shared';
import { brandScopeStyle } from './brandScope';
import { cx } from './utils';

export interface CompanyMarkProps {
    company: Pick<Company, 'name' | 'short_name' | 'colors'>;
    /** Square side in px (default 24). */
    size?: number;
    /** `tint` (rows, default) or `solid` (rail and phone header). */
    variant?: 'tint' | 'solid';
    /** Hide from assistive tech when the company name is already next to it. */
    decorative?: boolean;
    className?: string;
}

/**
 * Square letter chip in the company's own colors, computed per company so a
 * "Todas" table shows each row's brand. Switches with the theme.
 */
export function CompanyMark({ company, size = 24, variant = 'tint', decorative = false, className }: CompanyMarkProps) {
    const letter = (company.short_name || company.name).charAt(0).toUpperCase();
    const a11y = decorative ? { 'aria-hidden': true as const } : { role: 'img' as const, 'aria-label': company.name };

    return (
        <span
            {...a11y}
            title={company.name}
            className={cx(
                'brand-scope inline-flex shrink-0 items-center justify-center font-bold select-none',
                variant === 'tint' ? 'bg-brand-tint text-brand-ink' : 'bg-brand-mark font-display text-brand-mark-ink',
                className,
            )}
            style={{
                ...brandScopeStyle(company.colors),
                width: size,
                height: size,
                borderRadius: Math.round(size * (variant === 'tint' ? 0.25 : 0.28)),
                fontSize: Math.round(size * (variant === 'tint' ? 0.5 : 0.45)),
            }}
        >
            {letter}
        </span>
    );
}
