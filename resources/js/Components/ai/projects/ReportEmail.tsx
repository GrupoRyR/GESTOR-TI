import type { ReactNode } from 'react';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Icon } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import { formatLongDate, formatShortDate } from '@/lib/format';
import { PHASE_LABELS, requestStatus, type Phase } from '@/lib/status';
import type { ReportContent, ReportSection, ReportVersion } from '@/types/pages/ai-projects';
import type { Company } from '@/types/shared';
import { BudgetBars } from './ProjectParts';

export interface ReportEmailProps {
    content: ReportContent;
    company: Pick<Company, 'name' | 'short_name' | 'colors'> | undefined;
    sections: ReportSection[];
    version: ReportVersion;
    className?: string;
}

/** "miércoles 7 oct". */
const dayLabel = (iso: string) => `${formatLongDate(iso).split(' ')[0]} ${formatShortDate(iso)}`;

function Section({ title, aside, children }: { title: string; aside?: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-2 border-t border-line pt-4">
            <h3 className="flex items-center gap-2 text-[15px] font-bold">
                {title}
                {aside ? (
                    <span className="inline-flex items-center gap-1 text-xs font-semibold text-fg-muted">
                        <Icon name="lock" size={12} />
                        {aside}
                    </span>
                ) : null}
            </h3>
            {children}
        </section>
    );
}

/**
 * The project report e-mail as a 600 px column (B-ProjectReport preview).
 * Pure presentational, no tables for layout: the backend ports this markup
 * to a Blade mail view. The requester version never shows time or cost.
 */
export function ReportEmail({ content, company, sections, version, className }: ReportEmailProps) {
    const has = (section: ReportSection) => sections.includes(section);
    const phase = requestStatus(content.project.status).phase;
    const showBudget = version === 'internal' && has('budget') && content.budget !== null;

    return (
        <article className={cx('mx-auto flex w-full max-w-[600px] flex-col gap-4 bg-surface p-5 text-fg sm:p-7', className)}>
            <header className="flex items-center gap-2.5">
                {company ? <CompanyMark company={company} size={28} /> : null}
                <span className="text-[13px] font-bold">{company?.short_name ?? ''} · Gestor TI</span>
                <span className="ml-auto text-xs text-fg-muted">Informe al {dayLabel(content.as_of)}</span>
            </header>

            <div className="flex flex-col gap-1">
                <h2 className="font-display text-[24px] leading-tight font-bold tracking-[-0.02em]">{content.project.name}</h2>
                <p className="text-[13px] text-fg-muted">
                    {content.project.key} · pedido por {content.requester_name}
                    {content.request_key ? ` (${content.request_key})` : ''}
                </p>
            </div>

            {has('status') ? (
                <Section title="Estado y avance">
                    <div className="flex flex-wrap items-center gap-2 text-[13px] text-fg-soft">
                        <StatusChip status={content.project.status} size="sm" />
                        {content.tasks.done} de {content.tasks.total} tareas hechas · {content.criteria.done} de {content.criteria.total} criterios cumplidos
                    </div>
                    <ol className="grid grid-cols-5 gap-1" aria-label={`Fase ${phase} de 5: ${PHASE_LABELS[phase]}`}>
                        {([1, 2, 3, 4, 5] as Phase[]).map((index) => (
                            <li key={index} className="flex flex-col gap-1">
                                <span
                                    aria-hidden="true"
                                    className={cx('h-1.5 rounded-full', index < phase ? 'bg-fg' : index === phase ? 'bg-status-scheduled' : 'bg-line')}
                                />
                                <span className={cx('text-[11px]', index === phase ? 'font-bold text-fg' : 'text-fg-muted')}>{PHASE_LABELS[index]}</span>
                            </li>
                        ))}
                    </ol>
                </Section>
            ) : null}

            {has('done') && content.done.length > 0 ? (
                <Section title="Hecho esta semana">
                    <ul className="flex list-disc flex-col gap-1 pl-5 text-sm leading-snug">
                        {content.done.map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                </Section>
            ) : null}

            {has('next') && content.next.length > 0 ? (
                <Section title="Lo que sigue">
                    <ul className="flex list-disc flex-col gap-1 pl-5 text-sm leading-snug">
                        {content.next.map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                </Section>
            ) : null}

            {has('blockers') ? (
                <Section title="Bloqueos y requerimientos">
                    {content.blockers.length > 0 ? (
                        content.blockers.map((line) => (
                            <p key={line} className="rounded-control border-l-2 border-status-failure bg-status-failure-bg px-3 py-2 text-sm leading-snug">
                                {line}
                            </p>
                        ))
                    ) : (
                        <p className="text-sm text-fg-soft">Nada bloquea el proyecto esta semana.</p>
                    )}
                </Section>
            ) : null}

            {showBudget && content.budget ? (
                <Section title="Estimado contra real" aside="Solo en la versión interna">
                    <BudgetBars budget={content.budget} showCost={content.budget.cost !== null} />
                </Section>
            ) : null}

            <a
                href={content.project_url}
                className="inline-flex h-11 items-center justify-center self-start rounded-control bg-brand-button px-5 text-sm font-bold text-brand-button-ink no-underline"
            >
                Ver en el Gestor
            </a>
            <footer className="border-t border-line pt-3 text-xs leading-snug text-fg-muted">
                Recibes este informe porque sigues el proyecto {content.project.key}. Lo envía {content.sender_email}.
            </footer>
        </article>
    );
}
