import { router, useForm } from '@inertiajs/react';
import { useId, useRef, useState, type FormEvent } from 'react';
import { AcceptedTrace, GHOST_BUTTON, SUGGESTION_SURFACE, SuggestionBlock } from '@/Components/suggestions/SuggestionBlock';
import { CriteriaSuggestion } from '@/Components/suggestions/CriteriaSuggestion';
import { DuplicateSuggestionCard } from '@/Components/suggestions/DuplicateSuggestionCard';
import { addCriteriaToDraft, criteriaPayload, usedSuggestionItemIds, type CriteriaDraftItem } from '@/Components/suggestions/requestSuggestions';
import { Button } from '@/Components/ui/Button';
import { Dialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { TextArea } from '@/Components/ui/TextArea';
import { cx } from '@/Components/ui/utils';
import { route } from '@/lib/route';
import type { AcceptanceCriterion } from '@/types/ai';
import type { Suggestion } from '@/types/suggestions';

const RELOAD = { preserveScroll: true, preserveState: true, only: ['suggestions'] };

/** Inertia posts of every suggestion action. None of them changes the request by itself except summary "Usar". */
export function useSuggestionActions(requestKey: string) {
    return {
        accept: (id: number, value?: string) => router.post(route('ai.suggestions.accept', { suggestion: id }), value === undefined ? {} : { value }, RELOAD),
        discard: (id: number, reason: string | null = null) => router.post(route('ai.suggestions.discard', { suggestion: id }), reason ? { reason } : {}, RELOAD),
        regenerate: (id: number) => router.post(route('ai.suggestions.regenerate', { suggestion: id }), {}, RELOAD),
        cancel: (id: number) => router.post(route('ai.suggestions.cancel', { suggestion: id }), {}, RELOAD),
        suggest: () => router.post(route('ai.requests.suggestions.store', { request: requestKey }), {}, RELOAD),
    };
}

export type SuggestionActions = ReturnType<typeof useSuggestionActions>;

/** What is sent to the AI and what never is (D-SuggestStates). */
export function AiPrivacyNote({ provider, className }: { provider: string; className?: string }) {
    return (
        <div className={cx('flex flex-col gap-2.5 text-[13px] leading-normal', className)}>
            <p className="font-bold text-fg">Qué se le envía a la IA</p>
            <div className="flex flex-col gap-0.5">
                <b className="text-fg">Se envía</b>
                <span className="text-fg-soft">Título, problema, cómo se hace hoy, resultado esperado y app. Para duplicados, títulos de solicitudes abiertas.</span>
            </div>
            <div className="flex flex-col gap-0.5">
                <b className="text-fg">Nunca se envía</b>
                <span className="text-fg-soft">
                    Credenciales ni nada de la bóveda, tarifas, costos, salarios, datos de clientes, notas internas ni adjuntos.
                </span>
            </div>
            <p className="text-xs text-fg-muted">Proveedor: {provider}. Cada uso queda en el historial con quién y cuándo.</p>
        </div>
    );
}

export interface SuggestionsStripProps {
    count: number;
    provider: string;
    /** Shows "Sugerir con IA" when nothing was suggested yet. */
    onSuggest?: () => void;
}

/** One quiet line: how many suggestions, who sees them, and the privacy note behind a disclosure. */
export function SuggestionsStrip({ count, provider, onSuggest }: SuggestionsStripProps) {
    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span className="flex items-center gap-1.5 text-[13px] text-fg-soft">
                <Icon name="sparkle" size={14} />
                {count > 0
                    ? `${count} ${count === 1 ? 'sugerencia de IA' : 'sugerencias de IA'} · solo TI las ve · ninguna se aplica sola`
                    : 'Sin sugerencias de IA todavía · solo TI las ve'}
            </span>
            {onSuggest ? (
                <Button variant="secondary" icon="sparkle" onClick={onSuggest}>
                    Sugerir con IA
                </Button>
            ) : null}
            <details className="group relative">
                <summary className="flex min-h-11 cursor-pointer list-none items-center gap-1 rounded-control px-1.5 text-[13px] font-bold text-fg-soft underline underline-offset-[3px] hover:text-fg [&::-webkit-details-marker]:hidden">
                    <Icon name="info" size={14} />
                    Qué se envía
                </summary>
                <div className="z-20 mt-1 w-[min(360px,calc(100vw-32px))] rounded-card border border-line bg-surface p-4 shadow-[0_8px_24px_rgb(23_25_28/0.14)] md:absolute md:left-0">
                    <AiPrivacyNote provider={provider} />
                </div>
            </details>
        </div>
    );
}

export interface SummarySuggestionProps {
    suggestion: Suggestion<'summary'>;
    /** Jhon's saved "Resumen para TI" (maybe edited); falls back to the suggestion text. */
    summaryText: string | null;
    now: string;
    actions: SuggestionActions;
    /** "Pedir información" when the request is too short to summarize. */
    onAskInfo: () => void;
    /** Gallery: start in inline editing. */
    initiallyEditing?: boolean;
}

/** "Resumen para TI": the summary suggestion in every state, or Jhon's own text once used. */
export function SummarySuggestion({ suggestion, summaryText, now, actions, onAskInfo, initiallyEditing = false }: SummarySuggestionProps) {
    const [editing, setEditing] = useState(initiallyEditing);
    const [showOriginal, setShowOriginal] = useState(false);
    const [hidden, setHidden] = useState(false);
    const original = suggestion.payload?.text ?? '';
    const [draft, setDraft] = useState(original);
    const originalId = useId();

    if (hidden) return null;

    if (suggestion.state === 'accepted' && suggestion.accepted) {
        return (
            <div className="flex flex-col gap-1.5">
                <span>{summaryText ?? original}</span>
                <AcceptedTrace by={suggestion.accepted.by} at={suggestion.accepted.at} now={now} onViewOriginal={() => setShowOriginal((value) => !value)} />
                {showOriginal ? (
                    <div id={originalId} className={cx(SUGGESTION_SURFACE, 'px-3.5 py-2.5 text-sm text-fg-soft')}>
                        <span className="mb-1 block text-xs font-semibold text-fg-muted">Original de {suggestion.provider}</span>
                        {original}
                    </div>
                ) : null}
            </div>
        );
    }

    if (editing && suggestion.payload) {
        const submit = (event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
            if (!draft.trim()) return;
            actions.accept(suggestion.id, draft.trim());
            setEditing(false);
        };
        return (
            <form onSubmit={submit} aria-label="Editar el resumen sugerido" className={cx(SUGGESTION_SURFACE, 'flex flex-col gap-2.5 px-4 py-3')}>
                <TextArea label="Resumen para TI" hint="Queda como tuyo, con la marca “Basado en una sugerencia”." rows={4} value={draft} onChange={(event) => setDraft(event.target.value)} autoFocus />
                <div className="flex flex-wrap items-center gap-1.5">
                    <Button type="submit" variant="dark" icon="check" disabled={!draft.trim()}>
                        Usar este texto
                    </Button>
                    <button
                        type="button"
                        onClick={() => {
                            setDraft(original);
                            setEditing(false);
                        }}
                        className={GHOST_BUTTON}
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        );
    }

    const tooShort = suggestion.state === 'error' && suggestion.error?.code === 'too_short';

    return (
        <SuggestionBlock
            state={suggestion.state}
            provider={suggestion.provider}
            createdAt={suggestion.created_at}
            now={now}
            label="Sugerencia de IA: resumen"
            error={suggestion.error}
            stale={suggestion.stale}
            discarded={suggestion.discarded}
            discardedSummary="resumen"
            generatingNote="Puedes seguir evaluando mientras tanto."
            onUse={suggestion.payload ? () => actions.accept(suggestion.id) : undefined}
            onEdit={suggestion.payload ? () => setEditing(true) : undefined}
            onDiscard={() => actions.discard(suggestion.id)}
            onRegenerate={() => actions.regenerate(suggestion.id)}
            onCancel={() => actions.cancel(suggestion.id)}
            onRetry={() => actions.regenerate(suggestion.id)}
            onHide={() => setHidden(true)}
            actions={
                tooShort ? (
                    <div>
                        <Button variant="secondary" icon="question" onClick={onAskInfo}>
                            Pedir información
                        </Button>
                    </div>
                ) : undefined
            }
        >
            {suggestion.payload ? <p>{suggestion.payload.text}</p> : null}
        </SuggestionBlock>
    );
}

export interface DuplicateSuggestionsProps {
    requestKey: string;
    items: Suggestion<'duplicate'>[];
    now: string;
    actions: SuggestionActions;
    onMerge: (key: string) => void;
}

/** AI duplicate cards (neutral) plus the "No es duplicado" reason dialog. */
export function DuplicateSuggestions({ requestKey, items, now, actions, onMerge }: DuplicateSuggestionsProps) {
    const [target, setTarget] = useState<Suggestion<'duplicate'> | null>(null);
    const [reason, setReason] = useState('');
    const otherKey = target?.payload?.request.key ?? '';

    if (items.length === 0) return null;

    return (
        <>
            {items.map((item) => (
                <DuplicateSuggestionCard
                    key={item.id}
                    suggestion={item}
                    now={now}
                    compareHref={item.payload ? route('ai.requests.compare', { request: requestKey, other: item.payload.request.key }) : undefined}
                    onMerge={() => item.payload && onMerge(item.payload.request.key)}
                    onNotDuplicate={() => {
                        setReason('');
                        setTarget(item);
                    }}
                />
            ))}
            <Dialog
                open={target !== null}
                onClose={() => setTarget(null)}
                title={`¿${requestKey} no es duplicado de ${otherKey}?`}
                description={`Solo oculta esta sugerencia en ${requestKey} y queda en el historial. No cambia ${otherKey} ni entrena a la IA.`}
                onSubmit={(event) => {
                    event.preventDefault();
                    if (target) actions.discard(target.id, reason.trim() || null);
                    setTarget(null);
                }}
                actions={
                    <>
                        <Button variant="secondary" onClick={() => setTarget(null)}>
                            Cancelar
                        </Button>
                        <Button type="submit" variant="dark">
                            Ocultar sugerencia
                        </Button>
                    </>
                }
            >
                <TextArea
                    label="¿Por qué no es duplicado?"
                    optional
                    rows={3}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    placeholder={`Ej.: ${otherKey} es solo para la app; se necesita el correo`}
                />
            </Dialog>
        </>
    );
}

export interface CriteriaSectionProps {
    requestKey: string;
    criteria: AcceptanceCriterion[];
    suggestion: Suggestion<'criteria'> | null;
    /** Off or hidden: Jhon's form only, no suggestion block. */
    showSuggestion: boolean;
    now: string;
    actions: SuggestionActions;
}

/**
 * "Criterios de aceptación": Jhon's own list. Suggested items only fill the
 * form ("Usar", "Usar los N"); nothing saves until "Guardar criterios".
 */
export function CriteriaSection({ requestKey, criteria, suggestion, showSuggestion, now, actions }: CriteriaSectionProps) {
    const titleId = useId();
    const inputs = useRef<(HTMLInputElement | null)[]>([]);
    const focusIndex = useRef<number | null>(null);
    const form = useForm<{ criteria: CriteriaDraftItem[] }>({
        criteria: criteria.map((criterion) => ({ id: criterion.id, text: criterion.text, suggestion_item_id: null })),
    });
    const draft = form.data.criteria;

    const setDraft = (next: CriteriaDraftItem[], focus: number | null = null) => {
        focusIndex.current = focus;
        form.setData('criteria', next);
    };

    const add = (items: { id: number; text: string }[], focusItem = false) => {
        const next = addCriteriaToDraft(draft, items);
        const index = focusItem && items[0] ? next.findIndex((line) => line.suggestion_item_id === items[0]?.id) : null;
        setDraft(next, index);
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) => ({ ...criteriaPayload(data.criteria, suggestion?.id ?? null) }));
        form.put(route('ai.requests.criteria.update', { request: requestKey }), { preserveScroll: true });
    };

    return (
        <section aria-labelledby={titleId} className="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4 md:p-5">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h2 id={titleId} className="text-base font-bold">
                    Criterios de aceptación
                </h2>
                {draft.length === 0 ? <span className="text-[13px] text-fg-muted">Aún no hay criterios. Los que uses quedan como tuyos.</span> : null}
                <button type="button" onClick={() => setDraft([...draft, { id: null, text: '', suggestion_item_id: null }], draft.length)} className={cx(GHOST_BUTTON, 'ml-auto')}>
                    <Icon name="plus" size={14} />
                    Escribir uno
                </button>
            </div>

            {draft.length > 0 ? (
                <form onSubmit={submit} className="flex flex-col gap-2.5">
                    <ol className="flex flex-col gap-1.5">
                        {draft.map((line, index) => {
                            const error = (form.errors as Record<string, string | undefined>)[`criteria.${index}.text`];
                            return (
                                <li key={index} className="flex flex-col gap-1">
                                    <div className="flex items-center gap-2">
                                        <span aria-hidden="true" className="tabular flex size-6 shrink-0 items-center justify-center rounded-full bg-subtle text-xs font-bold text-fg-soft">
                                            {index + 1}
                                        </span>
                                        <input
                                            ref={(element) => {
                                                inputs.current[index] = element;
                                                if (element && focusIndex.current === index) {
                                                    focusIndex.current = null;
                                                    element.focus();
                                                }
                                            }}
                                            value={line.text}
                                            onChange={(event) => setDraft(draft.map((item, position) => (position === index ? { ...item, text: event.target.value } : item)))}
                                            aria-label={`Criterio ${index + 1}`}
                                            aria-invalid={error ? true : undefined}
                                            placeholder="Ej.: el asesor ve el aviso en Toma Pedidos"
                                            className="h-11 min-w-0 flex-1 rounded-control border border-line-strong bg-surface px-3 text-[15px] text-fg placeholder:text-fg-muted"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setDraft(draft.filter((_, position) => position !== index))}
                                            aria-label={`Quitar el criterio ${index + 1}`}
                                            className="flex size-11 shrink-0 items-center justify-center rounded-control text-fg-soft hover:bg-subtle hover:text-fg"
                                        >
                                            <Icon name="x" size={16} />
                                        </button>
                                    </div>
                                    {line.suggestion_item_id !== null ? (
                                        <span className="flex items-center gap-1 pl-8 text-xs text-fg-muted">
                                            <Icon name="sparkle" size={12} />
                                            De la sugerencia · ahora es tuyo
                                        </span>
                                    ) : null}
                                    {error ? <span className="pl-8 text-xs font-semibold text-status-failure">{error}</span> : null}
                                </li>
                            );
                        })}
                    </ol>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="submit" variant="secondary" icon="check" loading={form.processing} disabled={!form.isDirty}>
                            Guardar criterios
                        </Button>
                        <span aria-live="polite" className="text-xs text-fg-muted">
                            {form.recentlySuccessful ? 'Guardados' : form.isDirty ? 'Sin guardar' : ''}
                        </span>
                    </div>
                </form>
            ) : null}

            {showSuggestion && suggestion ? (
                <CriteriaSuggestion
                    suggestion={suggestion}
                    now={now}
                    usedIds={usedSuggestionItemIds(draft)}
                    onUseItem={(item) => add([item])}
                    onUseAll={(items) => add(items)}
                    onEditItem={(item) => add([item], true)}
                    onDiscard={() => actions.discard(suggestion.id)}
                    onRegenerate={() => actions.regenerate(suggestion.id)}
                    onRetry={() => actions.regenerate(suggestion.id)}
                    onCancel={() => actions.cancel(suggestion.id)}
                />
            ) : null}
        </section>
    );
}
