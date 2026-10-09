import type { ReactNode } from 'react';
import type { StatusFamily } from '@/lib/status';
import { Icon, type IconName } from './Icon';
import { cx, FAMILY_TONE } from './utils';

export type ChipTone = StatusFamily | 'outline';

export interface ChipProps {
    tone?: ChipTone;
    icon?: IconName;
    /** Small leading dot in the tone color (ignored when `icon` is set). */
    dot?: boolean;
    /** `md` 28 px (default) · `sm` 26 px for dense rows. */
    size?: 'sm' | 'md';
    className?: string;
    title?: string;
    children: ReactNode;
}

/** Static label. Colors come from the six status families, never the brand. */
export function Chip({ tone = 'gray', icon, dot = false, size = 'md', className, title, children }: ChipProps) {
    const palette =
        tone === 'outline'
            ? 'border border-line-strong bg-transparent text-fg font-bold'
            : cx(FAMILY_TONE[tone].bg, FAMILY_TONE[tone].text, 'font-semibold');
    const dotColor = tone === 'outline' ? 'bg-fg' : FAMILY_TONE[tone].dot;

    return (
        <span
            title={title}
            className={cx(
                'inline-flex max-w-full shrink-0 items-center gap-1.5 rounded-chip whitespace-nowrap',
                size === 'md' ? 'h-7 px-2.5 text-[13px]' : 'h-[26px] px-[9px] text-[13px]',
                palette,
                className,
            )}
        >
            {icon ? (
                <Icon name={icon} size={14} />
            ) : dot ? (
                <span aria-hidden="true" className={cx('size-2 shrink-0 rounded-full', dotColor)} />
            ) : null}
            <span className="truncate">{children}</span>
        </span>
    );
}
