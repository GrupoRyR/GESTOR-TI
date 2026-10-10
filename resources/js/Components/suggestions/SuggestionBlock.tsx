import { useId, useState, type ReactNode } from 'react';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { Skeleton } from '@/Components/ui/Skeleton';
import { cx } from '@/Components/ui/utils';
import { formatRelative } from '@/lib/format';
import type { SuggestionError, SuggestionState } from '@/types/suggestions';

/**
 * Neutral look of every AI suggestion: dashed gray border, sparkle tile,
 * dark main action. Never status colors, so it is not mistaken for a
 * warning or a decision.
 */
export const SUGGESTION_SURFACE = 'rounded-icon border border-dashed border-line-strong bg-ground';

/** Transparent text button used for the secondary suggestion actions. */
export const GHOST_BUTTON =
    'inline-flex h-11 shrink-0 items-center gap-1.5 rounded-control px-2.5 text-sm font-bold whitespace-nowrap text-fg-soft transition-colors duration-150 hover:bg-subtle hover:text-fg disabled:pointer-events-none disabled:opacity-50';

export function SparkleTile({ size = 28 }: { size?: number }) {
    return (
        <span
            aria-hidden="true"
            style={{ width: size, height: size }}
            className="flex shrink-0 items-center justify-center rounded-[8px] border border-line bg-surface text-fg-soft"
        >
            <Icon name="sparkle" size={Math.round(size * 0.57)} />
        </span>
    );
}

function rel(iso: string, now?: string): string {
    return formatRelative(iso, now ? new Date(now) : new Date());
}

export interface AcceptedTraceProps {
    by: string;
    at: string;
    now?: string;
    /** Opens the original suggestion (read-only). */
    onViewOriginal?: () => void;
    className?: string;
}

/** Quiet trace under content that started as a suggestion. */
export function AcceptedTrace({ by, at, now, onViewOriginal, className }: AcceptedTraceProps) {
    return (
        <p className={cx('flex flex-wrap items-center gap-x-1.5 text-xs text-fg-muted', className)}>
            <Icon name="sparkle" size={13} />
            <span>
                Basado en una sugerencia · {by} la usó {rel(at, now)}
            </span>
            {onViewOriginal ? (
                <>
                    <span aria-hidden="true">·</span>
                    <button type="button" onClick={onViewOriginal} className="min-h-11 font-bold text-fg-soft underline underline-offset-[3px] hover:text-fg">
                        Ver original
                    </button>
                </>
            ) : null}
        </p>
    );
}

export interface SuggestionBlockProps {
    state: SuggestionState;
    /** "DeepSeek". */
    provider: string;
    /** Null while generating. */
    createdAt?: string | null;
    now?: string;
    /** Accessible name of the region, e.g. "Sugerencia de IA: posible duplicado". */
    label?: string;
    /** After "Sugerencia de IA ·". */
    subtitle?: string;
    /** Bold line under the header ("Posible duplicado: GIA-121 · …"). */
    title?: ReactNode;
    /** The suggested content. */
    children?: ReactNode;
    /** Replaces the default actions of the current state. */
    actions?: ReactNode;
    /** Small note under the actions ("La talla final siempre es tuya."). */
    footnote?: ReactNode;

    useLabel?: string;
    onUse?: () => void;
    onEdit?: () => void;
    onDiscard?: () => void;
    onRegenerate?: () => void;
    /** generating */
    onCancel?: () => void;
    generatingNote?: string;
    /** error */
    error?: SuggestionError | null;
    onRetry?: () => void;
    onHide?: () => void;
    /** stale */
    stale?: { reason: string; at: string } | null;
    /** accepted */
    accepted?: { by: string; at: string } | null;
    onViewOriginal?: () => void;
    /** discarded: "posible duplicado de GIA-117". */
    discarded?: { by: string; at: string } | null;
    discardedSummary?: string;
    className?: string;
}

function Header({ provider, createdAt, now, subtitle, status }: { provider?: string; createdAt?: string | null; now?: string; subtitle?: string; status?: string }) {
    return (
        <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
            <SparkleTile />
            <span className="text-[13px]">
                <b className="text-fg">Sugerencia de IA</b>
                {subtitle ? <span className="text-fg-muted"> · {subtitle}</span> : null}
            </span>
            {status ? (
                <span role="status" className="ml-auto text-xs text-fg-muted">
                    {status}
                </span>
            ) : provider ? (
                <span className="ml-auto text-xs text-fg-muted">
                    {provider}
                    {createdAt ? ` · ${rel(createdAt, now)}` : ''}
                </span>
            ) : null}
        </div>
    );
}

/**
 * One AI suggestion in any state. Nothing applies by itself: "Usar" hands
 * the content to the page, which saves it as Jhon's own value.
 */
export function SuggestionBlock(props: SuggestionBlockProps) {
    const {
        state,
        provider,
        createdAt,
        now,
        label = 'Sugerencia de IA',
        subtitle = 'revísala antes de usarla',
        title,
        children,
        actions,
        footnote,
        useLabel = 'Usar',
        onUse,
        onEdit,
        onDiscard,
        onRegenerate,
        onCancel,
        generatingNote,
        error,
        onRetry,
        onHide,
        stale,
        accepted,
        onViewOriginal,
        discarded,
        discardedSummary,
        className,
    } = props;
    const [expanded, setExpanded] = useState(false);
    const bodyId = useId();

    if (state === 'accepted' && accepted) {
        return <AcceptedTrace by={accepted.by} at={accepted.at} now={now} onViewOriginal={onViewOriginal} className={className} />;
    }

    if (state === 'discarded') {
        return (
            <div className={cx('flex flex-col gap-2', className)}>
                <div className="flex min-h-11 items-center gap-2.5 rounded-control border border-dashed border-line-strong py-0.5 pr-1.5 pl-3.5 text-[13px] text-fg-muted">
                    <Icon name="sparkle" size={14} />
                    <span className="min-w-0 flex-1">
                        Sugerencia descartada
                        {discardedSummary ? ` · ${discardedSummary}` : ''}
                        {discarded ? ` · ${discarded.by}, ${rel(discarded.at, now)}` : ''}
                    </span>
                    {children ? (
                        <button type="button" aria-expanded={expanded} aria-controls={bodyId} onClick={() => setExpanded((value) => !value)} className={GHOST_BUTTON}>
                            {expanded ? 'Ocultar' : 'Mostrar'}
                        </button>
                    ) : null}
                </div>
                {children && expanded ? (
                    <section id={bodyId} aria-label={label} className={cx(SUGGESTION_SURFACE, 'flex flex-col gap-2 px-4 py-3 opacity-80')}>
                        {title ? <div className="text-[15px] font-bold text-fg">{title}</div> : null}
                        {children}
                    </section>
                ) : null}
            </div>
        );
    }

    if (state === 'generating') {
        return (
            <section aria-label={label} aria-busy="true" className={cx(SUGGESTION_SURFACE, 'flex flex-col gap-2.5 px-4 py-3', className)}>
                <Header status="Pensando…" />
                <Skeleton className="h-2.5 w-[92%] bg-line" />
                <Skeleton className="h-2.5 w-full bg-line" />
                <Skeleton className="h-2.5 w-[64%] bg-line" />
                <div className="flex items-center gap-2">
                    <Skeleton className="h-9 w-16 rounded-chip bg-line" />
                    <Skeleton className="h-9 w-16 rounded-chip" />
                    {actions ?? (onCancel ? (
                        <Button variant="secondary" className="ml-auto" onClick={onCancel}>
                            Cancelar
                        </Button>
                    ) : null)}
                </div>
                {generatingNote ? <p className="text-[13px] text-fg-soft">{generatingNote}</p> : null}
            </section>
        );
    }

    if (state === 'error') {
        return (
            <section role="alert" aria-label={label} className={cx(SUGGESTION_SURFACE, 'flex flex-col gap-2.5 px-4 py-3', className)}>
                <Header />
                <p className="flex items-start gap-2 text-sm leading-normal text-fg">
                    <Icon name="info" size={16} className="mt-0.5 text-fg-soft" />
                    <span>
                        <b>{error?.message ?? 'No se pudo generar la sugerencia.'}</b>
                        {error?.detail ? <span className="text-fg-soft"> {error.detail}</span> : null}
                    </span>
                </p>
                {actions ?? (onRetry || onHide ? (
                    <div className="flex flex-wrap items-center gap-2">
                        {onRetry ? (
                            <Button variant="dark" icon="refresh" onClick={onRetry}>
                                Volver a intentar
                            </Button>
                        ) : null}
                        {onHide ? (
                            <button type="button" onClick={onHide} className={GHOST_BUTTON}>
                                Ocultar
                            </button>
                        ) : null}
                    </div>
                ) : null)}
                {error ? (
                    <p className="text-xs text-fg-muted">
                        {rel(error.at, now).replace(/^hoy/, 'Hoy')} · código {error.code}
                    </p>
                ) : null}
            </section>
        );
    }

    const isStale = state === 'stale';
    const defaultActions = isStale ? (
        <div className="flex flex-wrap items-center gap-1">
            {onRegenerate ? (
                <Button variant="dark" icon="refresh" onClick={onRegenerate}>
                    Volver a sugerir
                </Button>
            ) : null}
            {onUse ? (
                <button type="button" onClick={onUse} className={GHOST_BUTTON}>
                    Usar igual
                </button>
            ) : null}
        </div>
    ) : (
        <div className="flex flex-wrap items-center gap-1.5">
            {onUse ? (
                <Button variant="dark" icon="check" onClick={onUse}>
                    {useLabel}
                </Button>
            ) : null}
            {onEdit ? (
                <Button variant="secondary" icon="edit" onClick={onEdit}>
                    Editar
                </Button>
            ) : null}
            {onDiscard ? (
                <button type="button" onClick={onDiscard} className={GHOST_BUTTON}>
                    Descartar
                </button>
            ) : null}
            {onRegenerate ? (
                <button type="button" onClick={onRegenerate} className={GHOST_BUTTON}>
                    <Icon name="refresh" size={14} />
                    Volver a sugerir
                </button>
            ) : null}
        </div>
    );

    return (
        <section aria-label={label} className={cx(SUGGESTION_SURFACE, 'flex flex-col gap-2.5 px-4 py-3', className)}>
            <Header provider={provider} createdAt={createdAt} now={now} subtitle={isStale ? undefined : subtitle} />
            {isStale && stale ? (
                <p className="rounded-chip border border-line bg-surface px-2.5 py-2 text-[13px] leading-snug">
                    <b>La solicitud cambió después de esta sugerencia.</b>{' '}
                    <span className="text-fg-soft">
                        {stale.reason} {rel(stale.at, now)}.
                    </span>
                </p>
            ) : null}
            {title ? <div className="text-[15px] font-bold text-fg">{title}</div> : null}
            {children ? <div className={cx('flex flex-col gap-2 text-sm leading-normal', isStale ? 'text-fg-muted' : 'text-fg')}>{children}</div> : null}
            {actions ?? defaultActions}
            {footnote ? <p className="text-xs text-fg-muted">{footnote}</p> : null}
        </section>
    );
}
