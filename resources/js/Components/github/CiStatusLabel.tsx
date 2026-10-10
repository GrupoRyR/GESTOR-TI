import { Icon, type IconName } from '@/Components/ui/Icon';
import { cx, FAMILY_TONE } from '@/Components/ui/utils';
import type { StatusFamily } from '@/lib/status';
import type { CiStatus } from '@/types/github';

export const CI_STATUS: Record<CiStatus, { icon: IconName; label: string; short: string; family: StatusFamily }> = {
    passing: { icon: 'check-circle', label: 'CI pasando', short: 'Pasando', family: 'green' },
    failing: { icon: 'x-circle', label: 'CI falló', short: 'Falló', family: 'red' },
    running: { icon: 'clock', label: 'CI corriendo', short: 'Corriendo', family: 'blue' },
    none: { icon: 'minus-circle', label: 'Sin CI', short: 'Sin CI', family: 'gray' },
};

export interface CiStatusLabelProps {
    status: CiStatus;
    /** `text` icon + colored text (default) · `chip` tinted chip. */
    variant?: 'text' | 'chip';
    /** Drop the "CI" prefix when a heading already says it ("CI en main · Pasando"). */
    short?: boolean;
    className?: string;
}

/** CI result as icon + words; color never carries the meaning alone. */
export function CiStatusLabel({ status, variant = 'text', short = false, className }: CiStatusLabelProps) {
    const ci = CI_STATUS[status];
    const tone = FAMILY_TONE[ci.family];
    return (
        <span
            className={cx(
                'inline-flex shrink-0 items-center gap-1.5 text-[13px] font-semibold whitespace-nowrap',
                variant === 'chip' ? cx('h-[26px] rounded-chip px-[9px]', tone.bg, tone.text) : status === 'none' ? 'text-fg-muted' : tone.text,
                className,
            )}
        >
            <Icon name={ci.icon} size={14} />
            {short ? ci.short : ci.label}
        </span>
    );
}
