import { Link } from '@inertiajs/react';
import { KnowledgeTypeIcon, ReviewDateLabel, VisibilityBadge } from '@/Components/assistant/KnowledgeBadges';
import { cx } from '@/Components/ui/utils';
import { knowledgeTypeLabel } from '@/lib/knowledge';
import { route } from '@/lib/route';
import type { KnowledgeCardSummary } from '@/types/assistant';
import type { Company } from '@/types/shared';
import { CompanyScope } from './KnowledgeParts';

export interface KnowledgeListProps {
    cards: KnowledgeCardSummary[];
    companies: Company[];
    today: string;
    /** Managers see visibility and review; read-only users do not. */
    manage: boolean;
}

const GRID_MANAGE = 'grid-cols-[36px_minmax(0,1fr)_128px_96px_156px_150px_150px]';
const GRID_READ = 'grid-cols-[36px_minmax(0,1fr)_128px_120px_180px]';

/**
 * Cards of the knowledge base: a table from 1280 px (C-Knowledge) and
 * two-line cards below it (C-KnowledgePhone). Each row is one link.
 */
export function KnowledgeList({ cards, companies, today, manage }: KnowledgeListProps) {
    const grid = manage ? GRID_MANAGE : GRID_READ;

    return (
        <>
            <div aria-hidden="true" className={cx('hidden h-10 items-center gap-x-3.5 bg-subtle px-5 text-xs font-bold text-fg-muted xl:grid', grid)}>
                <span />
                <span>Ficha</span>
                <span>Empresa</span>
                <span>Área</span>
                {manage ? <span>Visibilidad</span> : null}
                <span>Dueño</span>
                {manage ? <span>Revisión</span> : null}
            </div>
            <ul aria-label="Fichas" className="flex flex-col">
                {cards.map((card) => {
                    const type = knowledgeTypeLabel(card.type);
                    const href = route('assistant.knowledge.show', { card: card.key });
                    const overdue = card.review_status === 'overdue';
                    return (
                        <li key={card.id} className="border-line not-first:border-t xl:border-t">
                            {/* Desktop table row */}
                            <Link
                                href={href}
                                className={cx(
                                    'hidden min-h-14 items-center gap-x-3.5 px-5 py-2 text-sm hover:bg-ground focus-visible:-outline-offset-2 xl:grid',
                                    grid,
                                )}
                            >
                                <KnowledgeTypeIcon type={card.type} size="md" />
                                <span className="flex min-w-0 flex-col">
                                    <b className="truncate font-semibold">{card.title}</b>
                                    <span className="tabular truncate text-xs text-fg-muted">
                                        {card.key} · {type}
                                    </span>
                                </span>
                                <CompanyScope companyIds={card.company_ids} companies={companies} />
                                <span className="truncate">{card.area?.name ?? '—'}</span>
                                {manage ? (
                                    <span>
                                        <VisibilityBadge visibility={card.visibility} />
                                    </span>
                                ) : null}
                                <span className="truncate">{card.owner?.name ?? 'Sin dueño'}</span>
                                {manage ? (
                                    <span>
                                        <ReviewDateLabel reviewAt={card.review_at} today={today} status={card.review_status} />
                                    </span>
                                ) : null}
                            </Link>

                            {/* Phone and tablet card */}
                            <Link href={href} className="flex min-h-[76px] items-start gap-3 px-4 py-3.5 hover:bg-ground focus-visible:-outline-offset-2 md:px-5 xl:hidden">
                                <KnowledgeTypeIcon type={card.type} size="lg" />
                                <span className="flex min-w-0 flex-1 flex-col gap-1">
                                    <b className="text-[15px] leading-snug font-semibold">{card.title}</b>
                                    <span className="tabular text-[13px] text-fg-muted">
                                        {card.key} · {type}
                                        {card.area ? ` · ${card.area.name}` : ''}
                                        <span className="max-md:hidden">{card.owner ? ` · ${card.owner.name}` : ''}</span>
                                    </span>
                                    {manage ? (
                                        <span className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                            <VisibilityBadge visibility={card.visibility} size="sm" />
                                            {overdue ? (
                                                <ReviewDateLabel reviewAt={card.review_at} today={today} status={card.review_status} className="h-6 px-2 text-xs" />
                                            ) : (
                                                <ReviewDateLabel
                                                    reviewAt={card.review_at}
                                                    today={today}
                                                    status={card.review_status}
                                                    className="text-xs max-md:hidden"
                                                />
                                            )}
                                        </span>
                                    ) : null}
                                </span>
                                <CompanyScope companyIds={card.company_ids} companies={companies} variant="marks" className="mt-0.5" />
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </>
    );
}
