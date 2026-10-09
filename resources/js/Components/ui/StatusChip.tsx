import { requestStatus, type RequestStatusKey } from '@/lib/status';
import { Chip } from './Chip';
import type { IconName } from './Icon';

export const STATUS_ICONS: Record<RequestStatusKey, IconName> = {
    received: 'inbox',
    evaluating: 'search',
    needs_info: 'question',
    pending_approval: 'hourglass',
    approved: 'check-circle',
    backlog: 'list',
    rejected: 'x-circle',
    planned: 'calendar',
    in_development: 'code',
    in_testing: 'clipboard-check',
    in_production: 'signal',
    closed: 'archive',
};

export interface StatusChipProps {
    status: RequestStatusKey;
    size?: 'sm' | 'md';
    className?: string;
}

/** Request status: icon + Spanish label; the family color only groups. */
export function StatusChip({ status, size = 'md', className }: StatusChipProps) {
    const { label, family } = requestStatus(status);
    return (
        <Chip tone={family} icon={STATUS_ICONS[status]} size={size} className={className}>
            {label}
        </Chip>
    );
}
