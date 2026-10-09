import { useId, type ReactNode } from 'react';
import { cx } from './utils';

export interface PanelProps {
    title?: ReactNode;
    description?: ReactNode;
    /** Controls on the right of the title row. */
    actions?: ReactNode;
    /**
     * `true` (default): 24 px padding around everything. `false`: the title
     * row gets its own bordered header and the body runs edge to edge
     * (tables, lists).
     */
    padded?: boolean;
    as?: 'section' | 'div' | 'article';
    className?: string;
    bodyClassName?: string;
    children?: ReactNode;
}

/** Surface card: white (or dark surface), 1 px line, 18 px radius. */
export function Panel({ title, description, actions, padded = true, as: Tag = 'section', className, bodyClassName, children }: PanelProps) {
    const titleId = useId();
    const hasHeader = title !== undefined || actions !== undefined;

    const header = hasHeader ? (
        <div
            className={cx(
                'flex flex-wrap items-center justify-between gap-x-4 gap-y-2',
                !padded && 'border-b border-line px-5 py-4',
            )}
        >
            <div className="flex min-w-0 flex-col gap-1">
                {title !== undefined && (
                    <h2 id={titleId} className="font-display text-lg leading-tight font-bold">
                        {title}
                    </h2>
                )}
                {description ? <p className="text-[13px] leading-snug text-fg-muted">{description}</p> : null}
            </div>
            {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
        </div>
    ) : null;

    return (
        <Tag
            aria-labelledby={title !== undefined ? titleId : undefined}
            className={cx(
                'rounded-card border border-line bg-surface',
                padded ? 'flex flex-col gap-4 p-5 md:p-6' : 'overflow-hidden',
                className,
            )}
        >
            {header}
            {padded ? (
                children
            ) : (
                <div className={bodyClassName}>{children}</div>
            )}
        </Tag>
    );
}
