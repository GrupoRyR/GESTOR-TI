import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Icon } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import { route } from '@/lib/route';
import type { RoadmapItem } from '@/types/pages/ai-requester';
import type { Company } from '@/types/shared';
import { roadmapLine, voteLabel } from './roadmap';

export interface RoadmapCardProps {
    item: RoadmapItem;
    company: Pick<Company, 'name' | 'short_name' | 'colors'> | undefined;
}

/** +1 toggle: POST adds my vote, DELETE removes it. Own requests cannot be voted. */
function VoteButton({ item }: { item: RoadmapItem }) {
    const [voted, setVoted] = useState(item.voted);
    const votes = item.votes + (voted && !item.voted ? 1 : 0) - (!voted && item.voted ? 1 : 0);

    if (item.mine) {
        return votes > 0 ? (
            <span className="tabular inline-flex h-9 items-center gap-1 px-2 text-[13px] font-bold text-fg-muted" title={`${votes} ${votes === 1 ? 'voto' : 'votos'}`}>
                <Icon name="arrow-up" size={14} />+{votes}
            </span>
        ) : null;
    }

    const toggle = () => {
        const url = route('ai.requests.vote', { request: item.key });
        const options = { preserveScroll: true, preserveState: true, onError: () => setVoted(voted) };
        setVoted(!voted);
        if (voted) router.delete(url, options);
        else router.post(url, {}, options);
    };

    return (
        <button
            type="button"
            aria-pressed={voted}
            aria-label={voteLabel(votes, voted)}
            title={voteLabel(votes, voted)}
            onClick={toggle}
            className={cx(
                'tabular -my-1 inline-flex h-11 min-w-11 items-center justify-center gap-1 rounded-control border px-2.5 text-[13px] font-bold transition-colors duration-150',
                voted ? 'border-fg bg-fg text-on-fg' : 'border-line-strong bg-surface text-fg hover:border-fg-muted',
            )}
        >
            {votes > 0 ? (
                <>
                    <Icon name="arrow-up" size={14} strokeWidth={2.6} />+{votes}
                </>
            ) : (
                '+1'
            )}
        </button>
    );
}

export function RoadmapCard({ item, company }: RoadmapCardProps) {
    return (
        <article className={cx('flex flex-col gap-2 rounded-[14px] border bg-surface p-3.5', item.mine ? 'border-fg/40' : 'border-line')}>
            <div className="flex items-center gap-2">
                {company ? <CompanyMark company={company} size={22} /> : null}
                <b className="tabular text-[13px] text-fg-soft">{item.key}</b>
                {item.mine ? (
                    <span className="rounded-[6px] bg-brand-tint px-1.5 py-px text-xs font-bold text-brand-ink">Tuya</span>
                ) : null}
                <span className="ml-auto">
                    <VoteButton item={item} />
                </span>
            </div>
            <Link
                href={route('ai.requests.show', { request: item.key })}
                className="text-[15px] leading-snug font-bold text-fg no-underline hover:underline hover:underline-offset-2"
            >
                {item.title}
            </Link>
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <StatusChip status={item.status} size="sm" />
                <span className="text-[13px] text-fg-muted">{roadmapLine(item)}</span>
            </div>
        </article>
    );
}
