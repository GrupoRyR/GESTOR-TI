import { Link, router, useForm } from '@inertiajs/react';
import { useId, useState, type FormEvent } from 'react';
import { KnowledgeTypeIcon } from '@/Components/assistant/KnowledgeBadges';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { Dialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { TextArea } from '@/Components/ui/TextArea';
import { cx } from '@/Components/ui/utils';
import { draftApproval, joinEs } from '@/lib/curation';
import { formatRelative } from '@/lib/format';
import { knowledgeTypeLabel } from '@/lib/knowledge';
import { route } from '@/lib/route';
import type { KnowledgeVisibility, LearnDraft } from '@/types/assistant';
import type { DraftApprovePayload, DraftDiscardPayload } from '@/types/pages/assistant-curation';
import { FieldOriginTag } from './CurationParts';

const CHANNEL = { telegram: { label: 'Telegram', icon: 'telegram' }, app: { label: 'App', icon: 'chat' } } as const;

const quote = (text: string) => `«${text}»`;

// ---- list -------------------------------------------------------------------

export interface DraftListItemProps {
    draft: LearnDraft;
    current: boolean;
    now: Date;
}

/** One pending draft: who, channel and when, the message, the proposed type and duplicate hint. */
export function DraftListItem({ draft, current, now }: DraftListItemProps) {
    const channel = CHANNEL[draft.channel];
    return (
        <Link
            href={route('assistant.drafts.index', { borrador: draft.id })}
            only={['selected', 'requested']}
            preserveState
            preserveScroll
            aria-current={current ? 'true' : undefined}
            className={cx(
                'flex flex-col gap-2.5 rounded-card border p-3.5 text-fg no-underline transition-colors duration-150',
                current ? 'border-brand-primary/50 bg-brand-tint shadow-[inset_3px_0_0_var(--color-brand-button)]' : 'border-line bg-surface hover:bg-subtle',
            )}
        >
            <span className="flex items-center gap-2.5">
                <Avatar name={draft.author.name} src={draft.author.avatar_url} size="sm" />
                <span className="flex min-w-0 flex-col">
                    <b className="truncate text-sm">{draft.author.name}</b>
                    <span className="flex items-center gap-1 text-xs text-fg-muted">
                        <Icon name={channel.icon} size={12} />
                        {channel.label} · {formatRelative(draft.received_at, now)}
                    </span>
                </span>
            </span>
            <blockquote className="m-0 line-clamp-3 text-sm leading-snug text-fg-soft">{quote(draft.message)}</blockquote>
            <span className="flex flex-wrap items-center gap-1.5">
                <span className="inline-flex h-6 items-center gap-1 rounded-chip bg-subtle px-2 text-xs font-semibold text-fg-soft">
                    {knowledgeTypeLabel(draft.proposed.type)}
                    {draft.linked_card ? ` · enlazado a ${draft.linked_card.key}` : ''}
                </span>
                {draft.similar_to ? (
                    <span className="inline-flex h-6 items-center gap-1 rounded-chip bg-status-maintenance-bg px-2 text-xs font-semibold text-status-maintenance">
                        <Icon name="alert-triangle" size={12} />
                        Se parece a {draft.similar_to.key}
                    </span>
                ) : null}
            </span>
        </Link>
    );
}

// ---- discard ------------------------------------------------------------------

function DiscardDialog({ draft, open, onClose }: { draft: LearnDraft; open: boolean; onClose: () => void }) {
    const form = useForm<DraftDiscardPayload>({ reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (form.data.reason.trim() === '') {
            form.setError('reason', 'Escribe el motivo. Le llega a quien mandó el /aprender.');
            return;
        }
        form.post(route('assistant.drafts.discard', { draft: draft.id }), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            onSubmit={submit}
            title="¿Descartar este borrador?"
            description={`No se crea ninguna ficha con «${draft.proposed.title}». ${draft.author.name} recibe el motivo.`}
            actions={
                <>
                    <Button variant="secondary" type="button" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button variant="danger" type="submit" loading={form.processing}>
                        Descartar borrador
                    </Button>
                </>
            }
        >
            <TextArea
                label="Motivo"
                rows={3}
                required
                value={form.data.reason}
                onChange={(event) => {
                    form.setData('reason', event.target.value);
                    if (form.errors.reason) form.clearErrors('reason');
                }}
                error={form.errors.reason}
                placeholder="Ya está en K-031, no aplica a Derivados…"
            />
        </Dialog>
    );
}

// ---- detail -------------------------------------------------------------------

export interface DraftDetailProps {
    draft: LearnDraft;
    /** 1-based position in the pending list. */
    position: number;
    total: number;
    /** First name of the viewer, for the phone greeting ("Leonardo, …"). */
    viewerName: string;
    viewerId: number;
    now: Date;
    /** Laravel errors from approve. */
    errors?: Partial<Record<string, string>>;
}

/** The proposed card with field origins, the duplicate warning and the decision. */
export function DraftDetail({ draft, position, total, viewerName, viewerId, now, errors }: DraftDetailProps) {
    const approval = draftApproval(draft);
    const form = useForm<DraftApprovePayload>({ visibility: draft.proposed.visibility, review_at: draft.proposed.review_at });
    const [discarding, setDiscarding] = useState(false);
    const reasonId = useId();
    const channel = CHANNEL[draft.channel].label;
    const when = formatRelative(draft.received_at, now);
    const typeLabel = knowledgeTypeLabel(draft.proposed.type);
    const mine = draft.author.id === viewerId;
    const reviewError = form.errors.review_at ?? errors?.review_at;

    const approve = () => form.post(route('assistant.drafts.approve', { draft: draft.id }), { preserveScroll: true });
    const merge = (cardKey: string) => router.post(route('assistant.drafts.merge', { draft: draft.id, card: cardKey }), {}, { preserveScroll: true });

    return (
        <section aria-label="Borrador seleccionado" className="flex min-w-0 flex-col gap-4 md:rounded-card md:border md:border-line md:bg-surface md:p-5 xl:p-6">
            <p className="flex items-start gap-2 text-sm text-fg-soft max-md:hidden">
                <span className="mt-0.5 text-fg-muted">
                    <Icon name={CHANNEL[draft.channel].icon} size={16} />
                </span>
                Borrador {position} de {total} · lo armó el asistente con {mine ? 'tu mensaje' : `el mensaje de ${draft.author.name}`} por {channel}, {when}
            </p>
            <div className="flex flex-col gap-2 md:hidden">
                <p className="text-[15px] leading-snug text-fg-soft">
                    {mine
                        ? `${viewerName}, le enseñaste esto al asistente por ${channel} ${when}`
                        : `${viewerName}, ${draft.author.name} le enseñó esto al asistente por ${channel} ${when}`}
                </p>
                <blockquote className="m-0 border-l-[3px] border-line-strong py-0.5 pl-3 text-[15px] leading-snug">{quote(draft.message)}</blockquote>
            </div>

            {draft.similar_to ? (
                <div role="note" className="flex flex-col gap-3 rounded-card border border-status-maintenance/40 bg-status-maintenance-bg p-4 max-md:sm:flex-row max-md:sm:items-center xl:flex-row xl:items-center">
                    <span className="flex min-w-0 flex-1 items-start gap-2.5 text-sm leading-snug">
                        <span className="mt-0.5 text-status-maintenance">
                            <Icon name="alert-triangle" size={18} />
                        </span>
                        <span className="flex flex-col gap-0.5">
                            <b>
                                Se parece a {draft.similar_to.key} {draft.similar_to.title}
                            </b>
                            <span className="text-fg-soft">
                                Casi todo ya está en esa ficha. Agrega lo nuevo a {draft.similar_to.key} en vez de crear una ficha repetida.
                            </span>
                        </span>
                    </span>
                    <span className="flex shrink-0 flex-wrap gap-2 max-sm:[&>*]:flex-1 md:max-xl:self-end">
                        <Button variant="quiet" href={route('assistant.knowledge.show', { card: draft.similar_to.key })}>
                            Ver {draft.similar_to.key}
                        </Button>
                        <Button variant="dark" icon="plus" onClick={() => draft.similar_to && merge(draft.similar_to.key)}>
                            Agregar a {draft.similar_to.key}
                        </Button>
                    </span>
                </div>
            ) : null}

            <div className="flex flex-col gap-4 max-md:rounded-card max-md:border max-md:border-line max-md:bg-surface max-md:p-4">
                <div className="flex items-center gap-3">
                    <KnowledgeTypeIcon type={draft.proposed.type} size="lg" />
                    <span className="flex min-w-0 flex-col">
                        <span className="text-[13px] font-semibold text-fg-muted">Ficha propuesta · {typeLabel}</span>
                        <b className="font-display text-lg leading-tight md:text-xl">{draft.proposed.title}</b>
                    </span>
                </div>
                {draft.linked_card ? (
                    <Link
                        href={route('assistant.knowledge.show', { card: draft.linked_card.key })}
                        className="inline-flex items-center gap-1.5 self-start text-sm font-semibold"
                    >
                        <Icon name="link" size={14} />
                        Enlazado a {draft.linked_card.key} {draft.linked_card.title}
                    </Link>
                ) : null}

                <dl className="grid overflow-hidden rounded-control border border-line sm:grid-cols-2 xl:grid-cols-3">
                    {draft.proposed.fields.map((field) => (
                        <div key={field.label} className="flex flex-col gap-1 border-b border-line bg-surface p-3 last:border-b-0 sm:border-r sm:even:border-r-0 xl:even:border-r xl:[&:nth-child(3n)]:border-r-0">
                            <dt className="flex flex-wrap items-center gap-1.5 text-[13px] text-fg-muted">
                                {field.label}
                                <FieldOriginTag origin={field.origin} />
                            </dt>
                            <dd className={cx('text-[15px]', field.value === null ? 'text-fg-muted' : 'font-semibold')}>
                                {field.value ?? 'Sin dato · complétalo al editar'}
                            </dd>
                        </div>
                    ))}
                </dl>

                <div className="flex flex-col gap-1">
                    <span className="text-[13px] text-fg-muted">Descripción</span>
                    <p className="text-[15px] leading-normal">{draft.proposed.description}</p>
                </div>

                <div className="flex flex-col gap-4 max-md:sm:flex-row max-md:sm:items-end max-md:sm:gap-6 xl:flex-row xl:items-end xl:gap-6">
                    <fieldset className="m-0 flex flex-col gap-1.5 border-0 p-0">
                        <legend className="mb-1.5 text-[13px] font-semibold">Visibilidad</legend>
                        <div className="flex flex-wrap gap-2">
                            {(['it', 'company'] as KnowledgeVisibility[]).map((value) => (
                                <label
                                    key={value}
                                    className={cx(
                                        'inline-flex h-11 cursor-pointer items-center gap-2 rounded-control border px-3 text-sm font-semibold',
                                        form.data.visibility === value ? 'border-fg' : 'border-line-strong',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name={`visibility-${draft.id}`}
                                        value={value}
                                        checked={form.data.visibility === value}
                                        onChange={() => form.setData('visibility', value)}
                                        className="size-4 accent-brand-button"
                                    />
                                    <Icon name={value === 'it' ? 'lock' : 'employees'} size={14} />
                                    {value === 'it' ? 'Solo TI' : 'Toda la empresa'}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <label className="flex flex-col gap-1.5 text-[13px] font-semibold">
                        Revisar el
                        <input
                            type="date"
                            value={form.data.review_at}
                            onChange={(event) => form.setData('review_at', event.target.value)}
                            aria-invalid={reviewError ? true : undefined}
                            className="h-11 rounded-control border border-line-strong bg-surface px-3 text-[15px] font-normal text-fg"
                        />
                        {reviewError ? <span className="text-status-failure">{reviewError}</span> : null}
                    </label>
                </div>
            </div>

            <div className="flex flex-col gap-3">
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <Button size="lg" icon="check" disabled={!approval.allowed} loading={form.processing} onClick={approve} aria-describedby={reasonId}>
                        Aprobar
                    </Button>
                    <div className="flex flex-col gap-2 min-[420px]:grid min-[420px]:grid-cols-2 sm:flex">
                        <Button variant="secondary" size="lg" icon="edit" href={route('assistant.knowledge.create', { borrador: draft.id })}>
                            Editar antes de aprobar
                        </Button>
                        <Button variant="secondary" size="lg" aria-haspopup="dialog" onClick={() => setDiscarding(true)}>
                            Descartar
                        </Button>
                    </div>
                </div>
                <p id={reasonId} className="text-[13px] leading-snug text-fg-muted">
                    {approval.reason ? (
                        <b className="text-status-failure">{approval.reason} </b>
                    ) : approval.missing.length > 0 ? (
                        <>
                            Falta {joinEs(approval.missing)}: no impide aprobar, lo completas después en la ficha.{' '}
                        </>
                    ) : null}
                    Al aprobar, la ficha queda publicada y el asistente la usa desde ya. Descartar te pide el motivo.
                </p>
            </div>

            <DiscardDialog draft={draft} open={discarding} onClose={() => setDiscarding(false)} />
        </section>
    );
}
