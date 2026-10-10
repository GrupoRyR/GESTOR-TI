import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { KnowledgeTypeIcon, ReviewDateLabel, VisibilityBadge } from '@/Components/assistant/KnowledgeBadges';
import { CompanyScope, FieldValue } from '@/Components/assistant/knowledge/KnowledgeParts';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { AssistantLayout } from '@/Layouts/AssistantLayout';
import { can } from '@/lib/can';
import { knowledgeTypeLabel, reviewSentence } from '@/lib/knowledge';
import { cardFieldRows, defaultReviewDate } from '@/lib/knowledgeFields';
import { bogotaDateKey, formatDate, formatRelative, formatShortDate, formatTime } from '@/lib/format';
import { route } from '@/lib/route';
import type { KnowledgeCard } from '@/types/assistant';
import type { KnowledgeShowProps } from '@/types/pages/assistant-knowledge';
import type { PageProps } from '@/types/shared';

const TYPE_NOUN: Record<KnowledgeCard['type'], string> = {
    system: 'un sistema',
    report: 'un informe',
    data_flow: 'un flujo de datos',
    procedure: 'un procedimiento',
    glossary: 'un término del glosario',
};

/** "15 ene 2027" (always with the year in the review block). */
function dateWithYear(date: string): string {
    return `${formatShortDate(date)} ${date.slice(0, 4)}`;
}

function Card({ title, actions, padded = true, className, children }: { title: string; actions?: ReactNode; padded?: boolean; className?: string; children: ReactNode }) {
    const id = `ficha-${title.toLowerCase().replace(/[^a-z]+/g, '-')}`;
    return (
        <section aria-labelledby={id} className={cx('flex min-w-0 flex-col rounded-card border border-line bg-surface', padded && 'gap-3 px-4 py-4 md:px-[22px]', className)}>
            <div className={cx('flex flex-wrap items-center justify-between gap-x-3 gap-y-1', !padded && 'px-4 pt-4 pb-3 md:px-[22px]')}>
                <h2 id={id} className="font-display text-lg leading-tight font-bold">
                    {title}
                </h2>
                {actions}
            </div>
            {children}
        </section>
    );
}

interface FieldCell {
    key: string;
    label: string;
    /** Spans both columns (steps, description). */
    wide: boolean;
    content: ReactNode;
}

/** Two-column placement (from 640 px): which cells sit on the right and which are on the first row. */
function placeCells(cells: FieldCell[]): (FieldCell & { right: boolean; top: boolean })[] {
    let column = 0;
    let firstRow = true;
    return cells.map((cell) => {
        if (cell.wide && column === 1) {
            column = 0;
            firstRow = false;
        }
        const right = !cell.wide && column === 1;
        const top = firstRow;
        if (cell.wide || column === 1) {
            column = 0;
            firstRow = false;
        } else {
            column = 1;
        }
        return { ...cell, right, top };
    });
}

/** Ficha de conocimiento: fields by type, related, review, usage and recent changes (C-KnowledgeCard). */
export default function KnowledgeShow(props: PageProps<KnowledgeShowProps>) {
    const { card, today, now: nowIso, unanswered_count, drafts_count } = props;
    const manage = can(props.auth.user, 'assistant.knowledge.manage');
    const now = new Date(nowIso);
    const [reviewing, setReviewing] = useState(false);

    const typeLabel = knowledgeTypeLabel(card.type);
    const overdue = card.review_status === 'overdue';
    const editHref = route('assistant.knowledge.edit', { card: card.key });
    const askHref = route('assistant.index', { fuente: card.key });
    const companyText = props.companies
        .filter((company) => card.company_ids.includes(company.id))
        .map((company) => company.short_name)
        .join(' y ');
    const audience =
        card.visibility === 'it'
            ? 'el asistente solo la usa para responder a quienes administran la base'
            : 'el asistente la usa para responder a cualquier persona con acceso al Asistente';
    const when = (iso: string) => (bogotaDateKey(iso) === today ? `hoy ${formatTime(iso)}` : formatRelative(iso, now));

    const markReviewed = () => {
        router.post(
            route('assistant.knowledge.review', { card: card.key }),
            {},
            { preserveScroll: true, onStart: () => setReviewing(true), onFinish: () => setReviewing(false) },
        );
    };

    const lastReview = card.last_reviewed_at
        ? `última revisión ${dateWithYear(bogotaDateKey(card.last_reviewed_at))}${card.last_reviewed_by ? ` por ${card.last_reviewed_by.name}` : ''}`
        : 'nunca se ha revisado';

    const cells: FieldCell[] = cardFieldRows(card).map((row) => {
        if (!Array.isArray(row.value)) return { key: row.key, label: row.label, wide: false, content: <FieldValue value={row.value} /> };
        return {
            key: row.key,
            label: row.label,
            wide: true,
            content:
                row.value.length === 0 ? (
                    <FieldValue value={null} />
                ) : (
                    <ol className="flex flex-col gap-2">
                        {row.value.map((step, index) => (
                            <li key={index} className="flex gap-2.5">
                                <span
                                    aria-hidden="true"
                                    className="tabular flex size-6 shrink-0 items-center justify-center rounded-full bg-subtle text-xs font-bold text-fg-soft"
                                >
                                    {index + 1}
                                </span>
                                <span className="pt-0.5">
                                    <span className="sr-only">Paso {index + 1}: </span>
                                    <FieldValue value={step} />
                                </span>
                            </li>
                        ))}
                    </ol>
                ),
        };
    });
    cells.push({
        key: 'owner',
        label: 'Dueño',
        wide: false,
        content: card.owner ? (
            <span>
                {card.owner.name}
                {card.owner.job_title ? <span className="text-fg-soft"> · {card.owner.job_title}</span> : null}
            </span>
        ) : (
            <span className="text-fg-muted">Sin dueño</span>
        ),
    });
    if (card.description) cells.push({ key: 'description', label: 'Descripción', wide: true, content: <span className="leading-normal">{card.description}</span> });

    const relatedCount = card.related_cards.length + card.related_products.length;
    const relatedTile = 'flex min-h-16 items-center gap-3 rounded-control border border-line px-3 py-2.5 hover:border-fg-muted';

    return (
        <AssistantLayout
            shared={props}
            title={`${card.key} ${card.title} · Base de conocimiento`}
            tab="knowledge"
            counts={manage ? { drafts: drafts_count, unanswered: unanswered_count } : undefined}
            breadcrumb={[{ label: 'Base de conocimiento', href: route('assistant.knowledge.index') }, { label: card.key }]}
        >
            <div className="flex min-w-0 flex-col gap-3.5">
                <section className="flex flex-col gap-4 rounded-card border border-line bg-surface px-4 py-4 md:flex-row md:items-start md:justify-between md:gap-6 md:px-6 md:py-5">
                    <div className="flex min-w-0 items-start gap-4">
                        <KnowledgeTypeIcon type={card.type} size="xl" className="max-md:hidden" />
                        <div className="flex min-w-0 flex-col gap-1.5">
                            <span className="tabular text-[13px] font-semibold text-fg-muted">
                                {typeLabel} · {card.key}
                            </span>
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                <h1 className="font-display text-2xl leading-tight font-bold tracking-[-0.02em] md:text-[30px]">{card.title}</h1>
                                <VisibilityBadge visibility={card.visibility} />
                            </div>
                            <p className="text-sm leading-relaxed text-fg-soft">
                                <CompanyScope companyIds={card.company_ids} companies={props.companies} variant="marks" className="mr-1.5 align-middle" />
                                {companyText || 'Sin empresa'}
                                {card.area ? ` · ${card.area.name}` : ''} · {audience}
                            </p>
                        </div>
                    </div>
                    <div className="flex shrink-0 flex-wrap gap-2">
                        <Link href={askHref} className={buttonClasses({ variant: 'secondary' })}>
                            <Icon name="chat" size={16} />
                            Preguntar al asistente
                        </Link>
                        {manage ? (
                            <Link href={editHref} className={buttonClasses({ variant: 'dark' })}>
                                <Icon name="edit" size={16} />
                                Editar
                            </Link>
                        ) : null}
                    </div>
                </section>

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] lg:items-start">
                    <div className="flex min-w-0 flex-col gap-4">
                        <Card title="Qué dice la ficha" padded={false} actions={<span className="text-[13px] text-fg-muted">Campos de {TYPE_NOUN[card.type]}</span>}>
                            <dl className="grid border-t border-line sm:grid-cols-2">
                                {placeCells(cells).map((cell) => (
                                    <div
                                        key={cell.key}
                                        className={cx(
                                            'flex min-w-0 flex-col gap-1.5 border-line px-4 py-4 not-first:border-t md:px-[22px]',
                                            cell.wide && 'sm:col-span-2',
                                            cell.right && 'sm:border-l',
                                            cell.top && 'sm:border-t-0',
                                        )}
                                    >
                                        <dt className="text-[13px] font-semibold text-fg-muted">{cell.label}</dt>
                                        <dd className="text-[15px] break-words">{cell.content}</dd>
                                    </div>
                                ))}
                            </dl>
                        </Card>

                        <Card
                            title="Relacionadas"
                            actions={
                                manage ? (
                                    <Link href={editHref} className="flex min-h-11 items-center gap-1 text-[13px] font-bold text-fg">
                                        <Icon name="plus" size={14} />
                                        Agregar relación
                                    </Link>
                                ) : undefined
                            }
                        >
                            {relatedCount === 0 ? (
                                <p className="text-sm text-fg-muted">Sin fichas ni productos relacionados todavía.</p>
                            ) : (
                                <ul className="grid gap-2.5 sm:grid-cols-2">
                                    {card.related_cards.map((ref) => (
                                        <li key={ref.key}>
                                            <Link href={route('assistant.knowledge.show', { card: ref.key })} className={relatedTile}>
                                                <KnowledgeTypeIcon type={ref.type} size="md" />
                                                <span className="flex min-w-0 flex-col gap-0.5">
                                                    <b className="text-sm leading-snug font-semibold">{ref.title}</b>
                                                    <span className="tabular flex flex-wrap items-center gap-1 text-xs text-fg-muted">
                                                        {ref.key} · {knowledgeTypeLabel(ref.type)} ·
                                                        {ref.visibility === 'it' ? <Icon name="lock" size={12} /> : null}
                                                        {ref.visibility === 'it' ? 'Solo TI' : 'Toda la empresa'}
                                                    </span>
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                    {card.related_products.map((product) => (
                                        <li key={product.slug}>
                                            <Link href={route('ai.products.show', { product: product.slug })} className={relatedTile}>
                                                <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-icon bg-subtle text-fg-soft">
                                                    <Icon name="code" size={18} />
                                                </span>
                                                <span className="flex min-w-0 flex-col gap-0.5">
                                                    <b className="text-sm leading-snug font-semibold">{product.name}</b>
                                                    <span className="tabular text-xs text-fg-muted">
                                                        Producto · {product.slug} · {product.version}
                                                    </span>
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        {manage ? (
                            <>
                                <Card title="Revisión">
                                    <div className="flex items-start gap-3">
                                        <span
                                            aria-hidden="true"
                                            className={cx(
                                                'flex size-10 shrink-0 items-center justify-center rounded-icon',
                                                overdue ? 'bg-status-maintenance-bg text-status-maintenance' : 'bg-subtle text-fg-soft',
                                            )}
                                        >
                                            <Icon name={overdue ? 'alert-triangle' : 'calendar'} size={20} />
                                        </span>
                                        <span className="flex min-w-0 flex-col gap-1">
                                            {overdue ? (
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <b className="text-[15px]">La revisión venció</b>
                                                    <ReviewDateLabel reviewAt={card.review_at} today={today} status={card.review_status} />
                                                </span>
                                            ) : (
                                                <b className="text-[15px]">Revisar antes del {dateWithYear(card.review_at)}</b>
                                            )}
                                            <span className="text-[13px] text-fg-soft">
                                                {reviewSentence(card.review_at, today)} · {lastReview}
                                            </span>
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-line pt-3">
                                        <Button variant={overdue ? 'primary' : 'secondary'} icon="check" loading={reviewing} onClick={markReviewed}>
                                            Marcar como revisada
                                        </Button>
                                        <span className="text-[13px] text-fg-muted">
                                            Mueve la próxima revisión al {dateWithYear(defaultReviewDate(today))}, 6 meses desde hoy
                                        </span>
                                    </div>
                                </Card>

                                <section aria-label="Uso de la ficha" className="flex items-center gap-4 rounded-card border border-line bg-surface px-4 py-4 md:px-[22px]">
                                    <span className="tabular font-display text-[32px] leading-none font-bold">{card.usage_count_month}</span>
                                    <span className="flex min-w-0 flex-col gap-0.5">
                                        <b className="text-sm">
                                            {card.usage_count_month === 0
                                                ? 'Ninguna respuesta la citó este mes'
                                                : card.usage_count_month === 1
                                                  ? 'Citada en 1 respuesta este mes'
                                                  : `Citada en ${card.usage_count_month} respuestas este mes`}
                                        </b>
                                        {card.last_cited_at ? (
                                            <span className="text-[13px] text-fg-soft">
                                                La última, {when(card.last_cited_at)}
                                                {card.last_cited_by ? ` en el chat de ${card.last_cited_by.name}` : ''}
                                            </span>
                                        ) : null}
                                    </span>
                                </section>

                                <Card title="Últimos cambios">
                                    {card.recent_changes.length === 0 ? (
                                        <p className="text-sm text-fg-muted">Sin cambios registrados.</p>
                                    ) : (
                                        <ol className="flex flex-col">
                                            {card.recent_changes.map((change, index) => (
                                                <li key={change.id} className="relative grid grid-cols-[12px_minmax(0,1fr)] gap-3 py-2">
                                                    {index < card.recent_changes.length - 1 ? (
                                                        <span aria-hidden="true" className="absolute top-[26px] -bottom-1.5 left-[5.5px] w-px bg-line-strong" />
                                                    ) : null}
                                                    <span aria-hidden="true" className="flex justify-center pt-1.5">
                                                        <span className={cx('size-2.5 rounded-full', index === 0 ? 'bg-fg' : 'bg-line-strong')} />
                                                    </span>
                                                    <span className="flex min-w-0 flex-col gap-0.5">
                                                        <span className="text-sm">
                                                            <b>{change.who.name}</b> {change.what}
                                                        </span>
                                                        <time dateTime={change.at} className="text-xs text-fg-muted" title={formatDate(change.at)}>
                                                            {formatRelative(change.at, now)}
                                                        </time>
                                                    </span>
                                                </li>
                                            ))}
                                        </ol>
                                    )}
                                </Card>
                            </>
                        ) : (
                            <p className="text-[13px] text-fg-muted">Actualizada {formatRelative(card.updated_at, now)}. Si algo no está bien, avísale a TI.</p>
                        )}
                    </div>
                </div>
            </div>
        </AssistantLayout>
    );
}
