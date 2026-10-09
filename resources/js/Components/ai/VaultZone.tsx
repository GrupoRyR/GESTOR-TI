import { useId, type ReactNode } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';

export interface VaultZoneProps {
    title: ReactNode;
    /** Line under the title: who can enter, when it locks. */
    description?: ReactNode;
    /** Controls on the right of the header (countdown, Cerrar bóveda). */
    actions?: ReactNode;
    /** `section` (default) or `div` when nested in another landmark. */
    as?: 'section' | 'div';
    /** Heading level of the title: `h1` when the zone is the page itself (default `h2`). */
    titleAs?: 'h1' | 'h2';
    className?: string;
    children?: ReactNode;
}

/**
 * Protected zone for the vault and delivered secrets. Always dark, in the
 * light and the dark theme: `.vault-zone` (app.css) redefines the neutral
 * and status tokens inside it, so every component placed here renders dark.
 * The amber hazard stripe on top marks it as sensitive.
 */
export function VaultZone({ title, description, actions, as: Tag = 'section', titleAs: Heading = 'h2', className, children }: VaultZoneProps) {
    const titleId = useId();
    return (
        <Tag aria-labelledby={titleId} className={cx('vault-zone flex flex-col overflow-hidden rounded-card border border-line text-fg', className)}>
            <div aria-hidden="true" className="vault-stripe h-2.5 shrink-0" />
            <div className="flex flex-col gap-4 p-5 md:p-6">
                <div className="flex flex-wrap items-start gap-x-4 gap-y-3">
                    <div className="flex min-w-0 flex-1 basis-64 items-start gap-3">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-icon bg-status-maintenance-bg text-status-maintenance">
                            <Icon name="lock" size={20} />
                        </span>
                        <div className="flex min-w-0 flex-col gap-1">
                            <Heading id={titleId} className="font-display text-lg leading-tight font-bold md:text-xl">
                                {title}
                            </Heading>
                            {description ? <div className="text-[13px] leading-snug text-fg-soft">{description}</div> : null}
                        </div>
                    </div>
                    {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
                </div>
                {children}
            </div>
        </Tag>
    );
}
