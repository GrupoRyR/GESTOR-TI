import type { ReactNode } from 'react';
import { Chip } from '@/Components/ui/Chip';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import type { ProductState } from '@/types/ai';
import { PRODUCT_STATES } from './products';

/** Product state: icon + label, tone from the status family (never color alone). */
export function ProductStateChip({ state, size = 'sm', className }: { state: ProductState; size?: 'sm' | 'md'; className?: string }) {
    const meta = PRODUCT_STATES[state];
    return (
        <Chip tone={meta.tone} icon={meta.icon} size={size} className={className}>
            {meta.label}
        </Chip>
    );
}

/** "[DUEÑO]"-style placeholders stand out in amber so nobody mistakes them for data. */
export function Placeholderable({ text, className }: { text: string; className?: string }) {
    const placeholder = text.startsWith('[') && text.endsWith(']');
    return <span className={cx(placeholder ? 'font-semibold text-status-maintenance' : undefined, className)}>{text}</span>;
}

/** Lock line for figures that need `ai.costs.view`. */
export function CostsLockNote({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <p className={cx('flex items-start gap-2 text-[13px] leading-snug text-fg-muted', className)}>
            <Icon name="lock" size={14} className="mt-0.5 shrink-0" />
            <span>{children}</span>
        </p>
    );
}

/** Five rating squares, filled up to the rounded average (decorative; the number is written next to it). */
export function RatingSquares({ average }: { average: number }) {
    const filled = Math.round(average);
    return (
        <span aria-hidden="true" className="grid grid-cols-[repeat(5,18px)] gap-1">
            {[1, 2, 3, 4, 5].map((step) => (
                <span key={step} className={cx('h-1.5 rounded-full', step <= filled ? 'bg-fg' : 'bg-line-strong')} />
            ))}
        </span>
    );
}
