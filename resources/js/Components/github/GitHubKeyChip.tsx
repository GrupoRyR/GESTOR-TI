import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import type { LinkedWork } from '@/types/github';

export interface GitHubKeyChipProps {
    /** Work the GitHub item is linked to through its `GIA-123` key. */
    linked: Pick<LinkedWork, 'request_key' | 'task_key' | 'task_title'>;
    className?: string;
}

/** "GIA-139 · T3 Endpoint de zonas": what a PR or issue moves in the Gestor. */
export function GitHubKeyChip({ linked, className }: GitHubKeyChipProps) {
    const task = linked.task_key ? ` · ${linked.task_key}${linked.task_title ? ` ${linked.task_title}` : ''}` : '';
    return (
        <span
            className={cx(
                'inline-flex h-[26px] max-w-full shrink-0 items-center gap-1.5 rounded-chip border border-line bg-surface px-[9px] text-[13px] font-semibold whitespace-nowrap text-fg-soft',
                className,
            )}
        >
            <Icon name="link" size={13} />
            <span className="truncate">
                <span className="font-bold text-fg">{linked.request_key}</span>
                {task}
            </span>
        </span>
    );
}

export interface GitHubRefChipProps {
    kind: 'issue' | 'pr';
    number: number;
    /** Extra words after the number: "nuevo", "fusionado", "CI pasando". */
    note?: string;
    className?: string;
}

/** Compact "Issue #57 · nuevo" / "PR #36 · fusionado" chip for board cards. */
export function GitHubRefChip({ kind, number, note, className }: GitHubRefChipProps) {
    return (
        <span
            className={cx(
                'inline-flex h-[26px] shrink-0 items-center gap-1.5 rounded-chip bg-subtle px-[9px] text-[13px] font-semibold whitespace-nowrap text-fg-soft',
                className,
            )}
        >
            <Icon name={kind === 'issue' ? 'issue' : 'pull-request'} size={13} />
            <span className="tabular">
                {kind === 'issue' ? 'Issue' : 'PR'} #{number}
            </span>
            {note ? <span className="font-medium text-fg-muted">{note}</span> : null}
        </span>
    );
}
