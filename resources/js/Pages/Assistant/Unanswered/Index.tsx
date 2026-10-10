import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { KnowledgeTypeIcon } from '@/Components/assistant/KnowledgeBadges';
import { CurationNoPermission } from '@/Components/assistant/curation/CurationParts';
import { Button } from '@/Components/ui/Button';
import { ConfirmDialog, Dialog } from '@/Components/ui/Dialog';
import { EmptyState, StateTile } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { IconButton } from '@/Components/ui/IconButton';
import { cx } from '@/Components/ui/utils';
import { AssistantLayout } from '@/Layouts/AssistantLayout';
import { can } from '@/lib/can';
import { joinEs, timesLabel, unansweredTotals, type UnansweredOrder } from '@/lib/curation';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { KnowledgeCardRef, UnansweredQuestion } from '@/types/assistant';
import type { UnansweredIndexProps } from '@/types/pages/assistant-curation';
import type { PageProps } from '@/types/shared';

/** "Ya está documentada": search and pick the card that answers it. */
function LinkDialog({ question, cards, onClose }: { question: UnansweredQuestion | null; cards: KnowledgeCardRef[]; onClose: () => void }) {
    const [search, setSearch] = useState('');
    const [picked, setPicked] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const term = search.trim().toLowerCase();
    const matches = cards.filter((card) => term === '' || card.title.toLowerCase().includes(term) || card.key.toLowerCase().includes(term)).slice(0, 8);

    const close = () => {
        setSearch('');
        setPicked(null);
        setError(null);
        onClose();
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!question) return;
        if (!picked) {
            setError('Elige la ficha que responde la pregunta.');
            return;
        }
        router.post(route('assistant.unanswered.link', { question: question.id }), { card: picked }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: close,
        });
    };

    return (
        <Dialog
            open={question !== null}
            onClose={close}
            onSubmit={submit}
            title="¿Qué ficha la responde?"
            description={question ? `«${question.text}» sale de la lista y queda enlazada a la ficha que elijas.` : undefined}
            actions={
                <>
                    <Button variant="secondary" type="button" onClick={close}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={processing}>
                        Marcar como documentada
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-3">
                <label className="flex h-11 items-center gap-2 rounded-control border border-line-strong bg-surface px-3 has-focus-visible:outline-2 has-focus-visible:outline-fg">
                    <Icon name="search" size={16} className="text-fg-muted" />
                    <input
                        type="search"
                        aria-label="Buscar la ficha"
                        placeholder="Título o clave"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="h-full min-w-0 flex-1 bg-transparent text-[15px] outline-none placeholder:text-fg-muted"
                    />
                </label>
                <fieldset className="m-0 border-0 p-0" aria-invalid={error ? true : undefined}>
                    <legend className="sr-only">Fichas</legend>
                    {matches.length === 0 ? (
                        <p className="text-sm text-fg-muted">Ninguna ficha coincide. Si no existe, mejor usa “Documentar”.</p>
                    ) : (
                        <ul className="flex max-h-[300px] flex-col gap-1 overflow-y-auto">
                            {matches.map((card) => (
                                <li key={card.key}>
                                    <label
                                        className={cx(
                                            'flex min-h-11 cursor-pointer items-center gap-3 rounded-control border px-3 py-2',
                                            picked === card.key ? 'border-fg bg-subtle' : 'border-transparent hover:bg-subtle',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="card"
                                            value={card.key}
                                            checked={picked === card.key}
                                            onChange={() => {
                                                setPicked(card.key);
                                                setError(null);
                                            }}
                                            className="size-4 shrink-0 accent-brand-button"
                                        />
                                        <KnowledgeTypeIcon type={card.type} size="sm" />
                                        <span className="flex min-w-0 flex-col">
                                            <b className="truncate text-sm">{card.title}</b>
                                            <span className="tabular text-xs text-fg-muted">{card.key}</span>
                                        </span>
                                    </label>
                                </li>
                            ))}
                        </ul>
                    )}
                </fieldset>
                {error ? (
                    <p role="alert" className="flex items-center gap-1.5 text-[13px] font-semibold text-status-failure">
                        <Icon name="alert-triangle" size={14} />
                        {error}
                    </p>
                ) : null}
            </div>
        </Dialog>
    );
}

/** Preguntas sin respuesta (C-Unanswered): what the assistant could not answer, most asked first. */
export default function UnansweredIndex(props: PageProps<UnansweredIndexProps>) {
    const { questions, order, card_options, drafts_count, unanswered_count } = props;
    const manage = can(props.auth.user, 'assistant.knowledge.manage');
    const now = new Date(props.now);
    const totals = unansweredTotals(questions);
    const [linking, setLinking] = useState<UnansweredQuestion | null>(null);
    const [discarding, setDiscarding] = useState<UnansweredQuestion | null>(null);
    const [processing, setProcessing] = useState(false);

    const sort = (next: UnansweredOrder) => {
        router.get(route('assistant.unanswered.index'), next === 'recent' ? { orden: 'recientes' } : {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
    const discard = () => {
        if (!discarding) return;
        router.post(route('assistant.unanswered.discard', { question: discarding.id }), {}, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setDiscarding(null);
            },
        });
    };

    const guide = (
        <div className="flex flex-col gap-2 rounded-card border border-line bg-surface p-4 text-sm leading-snug">
            <span className="flex size-10 items-center justify-center rounded-icon bg-subtle text-fg-soft">
                <Icon name="question" size={20} />
            </span>
            <b className="text-[15px]">Esta lista dice qué le falta a la base</b>
            <span className="text-fg-soft">
                Cuando el asistente responde «No lo tengo documentado», la pregunta llega aquí. Documentar primero las que más se repiten es lo que más mejora
                sus respuestas.
            </span>
            <span className="text-fg-soft">
                <b className="text-fg">Documentar</b> abre una ficha nueva con la pregunta ya escrita.
            </span>
            <span className="text-fg-soft">
                <b className="text-fg">Ya está documentada</b> te pide la ficha que la responde.
            </span>
            <span className="text-fg-soft">
                <b className="text-fg">Descartar</b> la quita de la lista sin crear nada.
            </span>
        </div>
    );

    const content = !manage ? (
        <CurationNoPermission what="Ver las preguntas sin respuesta" />
    ) : (
        <div className="flex min-w-0 flex-col gap-4">
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[28px]">Preguntas sin respuesta</h1>
                    <p className="text-sm text-fg-soft">
                        {totals.questions === 0 ? (
                            'Nada pendiente por documentar.'
                        ) : (
                            <>
                                <b className="tabular text-fg">{totals.questions}</b> {totals.questions === 1 ? 'pregunta' : 'preguntas'} · se hicieron{' '}
                                <b className="tabular text-fg">{totals.asks}</b> {timesLabel(totals.asks)} en total
                            </>
                        )}
                    </p>
                </div>
                {totals.questions > 1 ? (
                    <label className="flex items-center gap-2 text-sm font-semibold max-sm:w-full">
                        Ordenar por
                        <span className="relative max-sm:flex-1">
                            <select
                                value={order}
                                onChange={(event) => sort(event.target.value as UnansweredOrder)}
                                className="h-11 w-full appearance-none rounded-control border border-line-strong bg-surface pr-9 pl-3 text-[15px] font-normal text-fg"
                            >
                                <option value="times">Más preguntadas</option>
                                <option value="recent">Más recientes</option>
                            </select>
                            <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-fg-muted">
                                <Icon name="chevron-down" size={16} />
                            </span>
                        </span>
                    </label>
                ) : null}
            </div>

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_280px] lg:items-start">
                {questions.length === 0 ? (
                    <EmptyState
                        icon="check-circle"
                        title="Todo documentado"
                        description="El asistente pudo responder todo lo que le preguntaron. Si algo le falta, aparece aquí."
                    />
                ) : (
                    <section
                        aria-label="Preguntas que el asistente no supo responder"
                        className="-mx-4 overflow-hidden border-y border-line bg-surface md:mx-0 md:rounded-card md:border"
                    >
                        <div aria-hidden="true" className="grid grid-cols-[56px_minmax(0,1fr)_auto_44px] gap-x-4 bg-subtle px-4 py-2.5 text-[13px] font-semibold text-fg-soft max-md:hidden">
                            <span>Veces</span>
                            <span>Pregunta · quiénes · última vez</span>
                            <span>Acciones</span>
                            <span />
                        </div>
                        <ul className="flex flex-col">
                            {questions.map((question) => (
                                <li
                                    key={question.id}
                                    className="grid grid-cols-[48px_minmax(0,1fr)_44px] items-start gap-x-3 gap-y-2.5 border-t border-line px-4 py-3.5 first:border-t-0 md:grid-cols-[56px_minmax(0,1fr)_auto_44px] md:items-center md:gap-x-4 md:first:border-t"
                                >
                                    <span className="flex flex-col items-center leading-none" aria-label={`${question.times_asked} ${timesLabel(question.times_asked)}`}>
                                        <span aria-hidden="true" className="tabular font-display text-2xl font-bold">
                                            {question.times_asked}
                                        </span>
                                        <span aria-hidden="true" className="text-xs text-fg-muted">
                                            {timesLabel(question.times_asked)}
                                        </span>
                                    </span>
                                    <span className="flex min-w-0 flex-col gap-0.5">
                                        <b className="text-[15px] leading-snug">{question.text}</b>
                                        <span className="text-[13px] text-fg-muted">
                                            {joinEs(question.askers.map((person) => person.name))} · última vez {formatRelative(question.last_asked_at, now)}
                                        </span>
                                    </span>
                                    <span className="col-span-3 flex flex-wrap items-center gap-2 md:col-span-1 md:flex-nowrap">
                                        <Button size="md" href={route('assistant.knowledge.create', { pregunta: question.id })} icon="plus" className="max-sm:flex-1">
                                            Documentar
                                        </Button>
                                        <Button variant="secondary" onClick={() => setLinking(question)} aria-haspopup="dialog" className="max-sm:flex-1">
                                            Ya está documentada
                                        </Button>
                                    </span>
                                    <IconButton
                                        icon="x"
                                        label={`Descartar la pregunta: ${question.text}`}
                                        onClick={() => setDiscarding(question)}
                                        className="-mt-2 -mr-2 max-md:col-start-3 max-md:row-start-1 md:m-0"
                                    />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <aside aria-label="Cómo usar esta lista">{guide}</aside>
            </div>

            <LinkDialog question={linking} cards={card_options} onClose={() => setLinking(null)} />
            <ConfirmDialog
                open={discarding !== null}
                onClose={() => setDiscarding(null)}
                onConfirm={discard}
                processing={processing}
                tone="danger"
                title="¿Descartar la pregunta?"
                description={discarding ? `«${discarding.text}» sale de la lista sin crear ninguna ficha. Si alguien la vuelve a hacer, aparece de nuevo.` : ''}
                confirmLabel="Descartar"
            />
        </div>
    );

    return (
        <AssistantLayout
            shared={props}
            title="Preguntas sin respuesta · Asistente TI"
            tab="unanswered"
            counts={manage ? { drafts: drafts_count, unanswered: unanswered_count } : undefined}
        >
            {content}
        </AssistantLayout>
    );
}
