import type { ReactNode } from 'react';
import type { StatusFamily } from '@/lib/status';
import { Icon, type IconName } from './Icon';
import { cx, FAMILY_TONE } from './utils';

export interface TimelineItem {
    id: string | number;
    /** Icon in a 24 px circle tinted with the family colors. */
    icon: IconName;
    family: StatusFamily;
    title: ReactNode;
    /** Quoted content (a question, an answer, a reason) in a soft bubble. */
    quote?: ReactNode;
    /** Date and actor line: "30 sep · 40 h · $ 6.400.000". */
    meta?: ReactNode;
}

export interface TimelineProps {
    items: TimelineItem[];
    /** Accessible name of the list, e.g. "Línea de tiempo de GIA-139". */
    label?: string;
    className?: string;
}

/** Vertical history, newest first, joined by a 2 px line. */
export function Timeline({ items, label, className }: TimelineProps) {
    return (
        <ol aria-label={label} className={cx('flex flex-col', className)}>
            {items.map((item, index) => {
                const last = index === items.length - 1;
                const tone = FAMILY_TONE[item.family];
                return (
                    <li
                        key={item.id}
                        className={cx(
                            'ml-[11px] grid grid-cols-[24px_minmax(0,1fr)] gap-x-3 border-l-2',
                            last ? 'border-transparent' : 'border-line pb-4',
                        )}
                    >
                        <span
                            aria-hidden="true"
                            className={cx('-ml-[13px] flex size-6 items-center justify-center rounded-full', tone.bg, tone.text)}
                        >
                            <Icon name={item.icon} size={14} />
                        </span>
                        <div className="flex min-w-0 flex-col gap-1 text-sm">
                            <span className="font-bold">{item.title}</span>
                            {item.quote ? (
                                <blockquote className="rounded-control bg-ground px-3 py-2.5 text-[13px] leading-snug">{item.quote}</blockquote>
                            ) : null}
                            {item.meta ? <span className="text-[13px] text-fg-muted">{item.meta}</span> : null}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
