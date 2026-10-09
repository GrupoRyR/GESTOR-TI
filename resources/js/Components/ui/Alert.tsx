import type { ReactNode } from 'react';
import { Icon, type IconName } from './Icon';
import { cx } from './utils';

export type AlertTone = 'info' | 'success' | 'warning' | 'danger';

const TONES: Record<AlertTone, { box: string; icon: IconName }> = {
    info: { box: 'border-status-scheduled/25 bg-status-scheduled-bg text-status-scheduled', icon: 'info' },
    success: { box: 'border-status-active/25 bg-status-active-bg text-status-active', icon: 'check-circle' },
    warning: { box: 'border-status-maintenance/25 bg-status-maintenance-bg text-status-maintenance', icon: 'alert-triangle' },
    danger: { box: 'border-status-failure-line bg-status-failure-bg text-status-failure', icon: 'alert-triangle' },
};

export interface AlertProps {
    tone?: AlertTone;
    title?: ReactNode;
    children?: ReactNode;
    actions?: ReactNode;
    /** Shows a close button. */
    onDismiss?: () => void;
    dismissLabel?: string;
    className?: string;
}

/** Inline message. Danger is announced immediately (`role="alert"`), the rest politely. */
export function Alert({ tone = 'info', title, children, actions, onDismiss, dismissLabel = 'Cerrar aviso', className }: AlertProps) {
    const { box, icon } = TONES[tone];
    return (
        <div
            role={tone === 'danger' ? 'alert' : 'status'}
            className={cx('flex items-start gap-3 rounded-control border px-4 py-3 text-sm', box, className)}
        >
            <span className="mt-0.5">
                <Icon name={icon} size={18} />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1 leading-snug">
                {title ? <p className="font-bold">{title}</p> : null}
                {children ? <div className="text-fg-soft">{children}</div> : null}
                {actions ? <div className="mt-1 flex flex-wrap gap-2">{actions}</div> : null}
            </div>
            {onDismiss ? (
                <button
                    type="button"
                    onClick={onDismiss}
                    aria-label={dismissLabel}
                    title={dismissLabel}
                    className="-my-2 -mr-2 inline-flex size-11 shrink-0 items-center justify-center rounded-control hover:bg-surface/60"
                >
                    <Icon name="x" size={18} />
                </button>
            ) : null}
        </div>
    );
}
