import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { IconButton } from '@/Components/ui/IconButton';
import { cx } from '@/Components/ui/utils';
import { companyScopeLabel, knowledgeTypeLabel } from '@/lib/knowledge';
import type { ChatSourcePreview } from '@/types/pages/assistant-chat';
import type { Company } from '@/types/shared';
import { KnowledgeTypeIcon, ReviewDateLabel, VisibilityBadge } from '../KnowledgeBadges';
import { sourceHref } from '../SourceList';

export interface SourcePreviewProps {
    source: ChatSourcePreview;
    /** `shared.companies` (marks and names). */
    companies: Company[];
    /** Bogotá `YYYY-MM-DD`. */
    today: string;
    onClose: () => void;
    className?: string;
}

function Row({ term, children, first = false }: { term: string; children: ReactNode; first?: boolean }) {
    return (
        <div className={cx('flex items-center justify-between gap-3 px-3.5 py-2.5 text-[13px]', !first && 'border-t border-subtle')}>
            <dt className="font-semibold text-fg-muted">{term}</dt>
            <dd className="flex min-w-0 items-center gap-1.5 text-right font-semibold">{children}</dd>
        </div>
    );
}

/** Desktop right column: the card an answer cited, without leaving the chat (C-ChatDesktop). */
export function SourcePreview({ source, companies, today, onClose, className }: SourcePreviewProps) {
    const names = Object.fromEntries(companies.map((company) => [company.id, company.short_name]));
    const single = source.company_ids.length === 1 ? companies.find((company) => company.id === source.company_ids[0]) : undefined;
    const scope = companyScopeLabel(source.company_ids, names);
    const type = knowledgeTypeLabel(source.type);

    return (
        <aside aria-label={`Vista previa de la fuente ${source.key}`} className={cx('flex min-h-0 flex-col overflow-hidden rounded-card border border-line bg-surface', className)}>
            <div className="flex shrink-0 items-center justify-between border-b border-line py-1.5 pr-3 pl-5">
                <span className="text-[13px] font-bold text-fg-soft">Vista previa de la fuente</span>
                <IconButton icon="x" label="Cerrar vista previa" iconSize={18} onClick={onClose} />
            </div>
            <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
                <div className="flex items-start gap-3">
                    <KnowledgeTypeIcon type={source.type} size="xl" />
                    <span className="flex min-w-0 flex-col gap-0.5">
                        <span className="text-xs font-semibold text-fg-muted">
                            {source.key} · {type}
                        </span>
                        <h2 className="font-display text-xl leading-tight font-bold tracking-[-0.02em]">{source.title}</h2>
                    </span>
                </div>
                {source.description ? <p className="text-sm leading-normal text-fg-soft">{source.description}</p> : null}
                <dl className="flex flex-col rounded-control border border-line">
                    <Row term="Tipo" first>
                        {type}
                    </Row>
                    <Row term="Empresa y área">
                        {single ? <CompanyMark company={single} size={20} decorative /> : null}
                        <span className="truncate">
                            {scope}
                            {source.area ? ` · ${source.area.name}` : ''}
                        </span>
                    </Row>
                    <Row term="Visibilidad">
                        <VisibilityBadge visibility={source.visibility} size="sm" />
                    </Row>
                    <Row term="Dueño">{source.owner ? source.owner.name : <span className="text-fg-muted">Sin dueño</span>}</Row>
                    <Row term="Próxima revisión">
                        <ReviewDateLabel reviewAt={source.review_at} today={today} status={source.review_status} />
                    </Row>
                </dl>
                <Link
                    href={sourceHref(source)}
                    className="mt-auto flex h-11 shrink-0 items-center justify-center rounded-control bg-fg text-sm font-bold text-on-fg transition-[filter] hover:brightness-[1.15] dark:hover:brightness-[0.9]"
                >
                    Abrir ficha
                </Link>
            </div>
        </aside>
    );
}
