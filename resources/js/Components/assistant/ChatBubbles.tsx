import { Link } from '@inertiajs/react';
import { useEffect, useRef, type ReactNode } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { inlineSegments, messageTime, paragraphs } from '@/lib/chat';

// ---- log --------------------------------------------------------------------

export interface ChatLogProps {
    children: ReactNode;
    /** Changes when a message arrives, to scroll to the end (e.g. the last message id). */
    scrollKey?: string | number;
    /** `aria-busy` while the answer is pending. */
    busy?: boolean;
    className?: string;
}

/**
 * Scrolling message list. `role="log"` + polite live region: new answers are
 * read without interrupting. Focusable so the keyboard can scroll it.
 */
export function ChatLog({ children, scrollKey, busy = false, className }: ChatLogProps) {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const node = ref.current;
        if (node) node.scrollTop = node.scrollHeight;
    }, [scrollKey]);

    return (
        <div
            ref={ref}
            role="log"
            aria-live="polite"
            aria-relevant="additions"
            aria-label="Conversación con el Asistente TI"
            aria-busy={busy || undefined}
            tabIndex={0}
            className={cx('flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto overscroll-contain focus-visible:-outline-offset-2', className)}
        >
            {children}
        </div>
    );
}

/** "Hoy, miércoles 7 de octubre". */
export function DayDivider({ label }: { label: string }) {
    return <p className="self-center text-xs font-semibold text-fg-muted">{label}</p>;
}

// ---- shared pieces ----------------------------------------------------------

/** Neutral tile with the assistant icon (headers, greeting). */
export function AssistantTile({ size = 36, className }: { size?: 36 | 44; className?: string }) {
    return (
        <span
            aria-hidden="true"
            className={cx('flex shrink-0 items-center justify-center bg-subtle text-fg-soft', size === 44 ? 'size-11 rounded-icon' : 'size-9 rounded-control', className)}
        >
            <Icon name="chat-dots" size={size === 44 ? 22 : 20} />
        </span>
    );
}

/** Answer text: paragraphs and `**bold**`, never HTML. */
export function AssistantText({ text, className }: { text: string; className?: string }) {
    return (
        <div className={cx('flex flex-col gap-2 text-[15px] leading-normal', className)}>
            {paragraphs(text).map((paragraph, index) => (
                <p key={index}>
                    {inlineSegments(paragraph).map((segment, part) => (segment.bold ? <b key={part}>{segment.text}</b> : <span key={part}>{segment.text}</span>))}
                </p>
            ))}
        </div>
    );
}

/** "Asistente TI · 9:40 a. m." */
export function AssistantFooter({ at }: { at: string }) {
    return <span className="text-[11px] text-fg-muted">Asistente TI · {messageTime(at)}</span>;
}

export interface AssistantBubbleProps {
    /** Accessible name of the article ("Respuesta del Asistente TI: no documentado"). */
    label: string;
    at?: string;
    /** `warning`: amber left edge (not documented). `danger`: red outline + `role="alert"`. */
    tone?: 'default' | 'warning' | 'danger';
    /** Full width on phone (pickers) instead of 94 %. */
    wide?: boolean;
    className?: string;
    children: ReactNode;
}

/** Assistant answer shell: white card, flat bottom-left corner. */
export function AssistantBubble({ label, at, tone = 'default', wide = false, className, children }: AssistantBubbleProps) {
    return (
        <article
            aria-label={label}
            role={tone === 'danger' ? 'alert' : undefined}
            className={cx(
                'flex flex-col gap-3 self-start rounded-[16px_16px_16px_4px] border bg-surface p-3.5 md:max-w-[94%] md:p-4',
                wide ? 'w-full md:w-[560px]' : 'w-[94%] md:w-auto',
                tone === 'danger' ? 'border-status-failure-line' : 'border-line',
                tone === 'warning' && 'border-l-4 border-l-status-maintenance-dot',
                className,
            )}
        >
            {children}
            {at ? <AssistantFooter at={at} /> : null}
        </article>
    );
}

// ---- simple messages --------------------------------------------------------

export interface UserMessageProps {
    text: string;
    at: string;
    className?: string;
}

/** The person's question: dark bubble on the right with its time. */
export function UserMessage({ text, at, className }: UserMessageProps) {
    return (
        <div className={cx('flex max-w-[84%] flex-col items-end gap-1 self-end md:max-w-[70%]', className)}>
            <p className="rounded-[16px_16px_4px_16px] bg-fg px-3.5 py-2.5 text-[15px] leading-[1.4] [overflow-wrap:anywhere] text-on-fg">
                <span className="sr-only">Tú: </span>
                {text}
            </p>
            <span className="text-[11px] text-fg-muted">{messageTime(at)}</span>
        </div>
    );
}

/** Short plain answer, e.g. the generic password refusal. */
export function RefusedMessage({ text, at }: { text: string; at: string }) {
    return (
        <div className="flex max-w-[90%] flex-col gap-1 self-start">
            <p className="rounded-[16px_16px_16px_4px] border border-line bg-surface px-3.5 py-2.5 text-[15px] leading-normal">
                <span className="sr-only">Asistente TI: </span>
                {text}
            </p>
            <AssistantFooter at={at} />
        </div>
    );
}

export interface AssistantErrorMessageProps {
    text: string;
    onRetry?: () => void;
    retrying?: boolean;
}

/** Provider failure on one question, with "Reintentar". */
export function AssistantErrorMessage({ text, onRetry, retrying = false }: AssistantErrorMessageProps) {
    return (
        <AssistantBubble label="El Asistente TI no respondió" tone="danger">
            <div className="flex items-start gap-2.5">
                <span aria-hidden="true" className="flex size-8 shrink-0 items-center justify-center rounded-chip bg-status-failure-bg text-status-failure">
                    <Icon name="alert-triangle" size={18} />
                </span>
                <p className="text-sm leading-[1.45]">{text}</p>
            </div>
            {onRetry ? (
                <button
                    type="button"
                    onClick={onRetry}
                    disabled={retrying}
                    className="inline-flex h-11 items-center gap-2 self-start rounded-control bg-fg px-4 text-sm font-bold text-on-fg transition-[filter] hover:brightness-[1.15] disabled:opacity-60 dark:hover:brightness-[0.9]"
                >
                    <Icon name="refresh" size={16} />
                    Reintentar
                </button>
            ) : null}
        </AssistantBubble>
    );
}

/** Pending answer: three dots + "Buscando en la base de conocimiento…". */
export function TypingIndicator({ text = 'Buscando en la base de conocimiento…' }: { text?: string }) {
    return (
        <div role="status" className="flex items-center gap-2.5 self-start rounded-[16px_16px_16px_4px] border border-line bg-surface px-3 py-2.5">
            <span aria-hidden="true" className="inline-flex gap-1">
                <span className="size-[7px] rounded-full bg-fg motion-safe:animate-pulse" />
                <span className="size-[7px] rounded-full bg-fg-muted motion-safe:animate-pulse [animation-delay:150ms]" />
                <span className="size-[7px] rounded-full bg-line-strong motion-safe:animate-pulse [animation-delay:300ms]" />
            </span>
            <span className="text-sm text-fg-soft">{text}</span>
        </div>
    );
}

// ---- phone header -----------------------------------------------------------

export interface AssistantPhoneHeaderProps {
    title: string;
    /** "Solo consulta · no cambia nada". */
    subtitle?: string;
    /** Back link instead of the assistant tile (e.g. knowledge base → chat). */
    back?: { href: string; label: string };
    /** 44 px icon buttons on the right. */
    actions?: ReactNode;
}

/** Phone header that replaces the app top bar on Asistente pages (C-ChatPhone, C-KnowledgePhone). */
export function AssistantPhoneHeader({ title, subtitle, back, actions }: AssistantPhoneHeaderProps) {
    return (
        <header className="sticky top-0 z-20 flex h-[60px] items-center gap-2.5 border-b border-line bg-surface pr-2 pl-4">
            {back ? (
                <Link
                    href={back.href}
                    aria-label={back.label}
                    className="-ml-2 flex size-11 shrink-0 items-center justify-center rounded-control text-fg hover:bg-subtle"
                >
                    <Icon name="chevron-left" size={20} />
                </Link>
            ) : (
                <AssistantTile />
            )}
            <span className="flex min-w-0 flex-col">
                <h1 className="truncate font-display text-lg leading-tight font-bold tracking-[-0.02em]">{title}</h1>
                {subtitle ? <span className="truncate text-xs text-fg-muted">{subtitle}</span> : null}
            </span>
            {actions ? <div className="ml-auto flex shrink-0 items-center">{actions}</div> : null}
        </header>
    );
}
