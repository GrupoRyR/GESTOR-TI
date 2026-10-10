import { Link } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { knowledgeTypeLabel } from '@/lib/knowledge';
import { route } from '@/lib/route';
import type { ChatSource } from '@/types/assistant';
import { KnowledgeTypeIcon } from './KnowledgeBadges';

/** `list`: stacked rows (phone) · `chips`: inline chips (desktop) · `responsive`: list below 768 px, chips above. */
export type SourceLayout = 'list' | 'chips' | 'responsive';

/** Card page of a source (named route `assistant.knowledge.show`). */
export const sourceHref = (source: ChatSource): string => route('assistant.knowledge.show', { card: source.key });

const LINK: Record<SourceLayout, string> = {
    list: 'flex min-h-[52px] items-center gap-2.5 rounded-control border py-1.5 pr-2 pl-1.5',
    chips: 'inline-flex h-11 items-center gap-2 rounded-control border pr-3 pl-1.5 text-sm',
    responsive: 'flex min-h-[52px] items-center gap-2.5 rounded-control border py-1.5 pr-2 pl-1.5 md:inline-flex md:h-11 md:min-h-0 md:gap-2 md:py-0 md:pr-3 md:text-sm',
};

const TEXT: Record<SourceLayout, string> = {
    list: 'flex min-w-0 flex-1 flex-col',
    chips: 'flex min-w-0 items-center gap-2',
    responsive: 'flex min-w-0 flex-1 flex-col md:flex-none md:flex-row md:items-center md:gap-2',
};

const KEY: Record<SourceLayout, string> = {
    list: 'text-xs font-semibold text-fg-muted',
    chips: 'font-semibold text-fg-muted',
    responsive: 'text-xs font-semibold text-fg-muted md:text-sm',
};

const DETAIL: Record<SourceLayout, string> = { list: '', chips: 'sr-only', responsive: 'md:sr-only' };
const CHEVRON: Record<SourceLayout, string> = { list: '', chips: 'hidden', responsive: 'md:hidden' };
const GROUP: Record<SourceLayout, string> = {
    list: 'flex flex-col gap-1.5',
    chips: 'flex flex-wrap gap-2',
    responsive: 'flex flex-col gap-1.5 md:flex-row md:flex-wrap md:gap-2',
};

export interface SourceListProps {
    sources: ChatSource[];
    layout?: SourceLayout;
    /** Source shown in the desktop preview panel (`aria-current`). */
    selectedKey?: string | null;
    /**
     * Desktop: open the preview instead of navigating. The link still points
     * to the card, so a new tab or the phone layout navigate normally.
     */
    onSelect?: (source: ChatSource) => void;
    hrefFor?: (source: ChatSource) => string;
    className?: string;
}

/** "Fuentes" of an answer: the cards it used, as links. Empty → "Sin fuentes para esta pregunta." */
export function SourceList({ sources, layout = 'responsive', selectedKey = null, onSelect, hrefFor = sourceHref, className }: SourceListProps) {
    const select = (event: MouseEvent, source: ChatSource) => {
        if (!onSelect || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
        event.preventDefault();
        onSelect(source);
    };

    return (
        <div role="group" aria-label="Fuentes de la respuesta" className={cx('flex flex-col gap-1.5 border-t border-subtle pt-2.5', className)}>
            <span className="text-xs font-bold text-fg-soft">Fuentes</span>
            {sources.length === 0 ? (
                <span className="text-[13px] text-fg-muted">Sin fuentes para esta pregunta.</span>
            ) : (
                <ul className={GROUP[layout]}>
                    {sources.map((source) => {
                        const selected = source.key === selectedKey;
                        const type = knowledgeTypeLabel(source.type);
                        return (
                            <li key={source.key} className="flex min-w-0">
                                <Link
                                    href={hrefFor(source)}
                                    aria-current={selected ? 'true' : undefined}
                                    onClick={(event) => select(event, source)}
                                    className={cx(
                                        'w-full min-w-0 text-fg transition-colors duration-150',
                                        LINK[layout],
                                        selected ? 'border-brand-button bg-brand-tint' : 'border-line bg-surface hover:bg-subtle',
                                    )}
                                >
                                    <KnowledgeTypeIcon
                                        type={source.type}
                                        size={layout === 'chips' ? 'sm' : 'md'}
                                        shape="chip"
                                        className={layout === 'responsive' ? 'md:size-8' : undefined}
                                    />
                                    <span className={TEXT[layout]}>
                                        <span className={KEY[layout]}>
                                            {source.key}
                                            <span className={DETAIL[layout]}>
                                                {` · ${type}`}
                                                {source.visibility === 'it' ? ' · Solo TI' : ''}
                                            </span>
                                        </span>
                                        <b className="truncate text-sm">{source.title}</b>
                                    </span>
                                    <Icon name="chevron-right" size={18} className={cx('ml-auto', CHEVRON[layout])} />
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

// ---- suggestions ------------------------------------------------------------

/** `stack` (phone) · `wrap` (desktop) · `grid` (2 columns, first open) · `responsive` stack → wrap. */
export type SuggestionLayout = 'stack' | 'wrap' | 'grid' | 'responsive';

const SUGGESTION_GROUP: Record<SuggestionLayout, string> = {
    stack: 'flex flex-col gap-1.5',
    wrap: 'flex flex-wrap gap-2',
    grid: 'grid grid-cols-2 gap-2',
    responsive: 'flex flex-col gap-1.5 md:flex-row md:flex-wrap md:gap-2',
};

export interface SuggestionChipsProps {
    suggestions: string[];
    /** Sends the suggestion as a new question. */
    onPick?: (text: string) => void;
    /** "También puedes preguntar" by default; "Sobre SAP sí tengo esto"… */
    title?: string | null;
    layout?: SuggestionLayout;
    disabled?: boolean;
    className?: string;
}

/** Suggested questions as 44 px buttons. */
export function SuggestionChips({ suggestions, onPick, title = 'También puedes preguntar', layout = 'responsive', disabled = false, className }: SuggestionChipsProps) {
    if (suggestions.length === 0) return null;
    return (
        <section aria-label="Preguntas sugeridas" className={cx('flex flex-col gap-1.5 md:gap-2', className)}>
            {title ? <span className="text-xs font-bold text-fg-soft">{title}</span> : null}
            <div className={SUGGESTION_GROUP[layout]}>
                {suggestions.map((suggestion) => (
                    <button
                        key={suggestion}
                        type="button"
                        disabled={disabled}
                        onClick={() => onPick?.(suggestion)}
                        className={cx(
                            'rounded-control border border-line-strong bg-surface px-3.5 text-left font-semibold text-fg transition-colors duration-150 hover:bg-subtle disabled:opacity-60',
                            layout === 'grid' ? 'min-h-14 px-2.5 py-2 text-[13px] leading-[1.3]' : 'min-h-11 py-2 text-sm',
                        )}
                    >
                        {suggestion}
                    </button>
                ))}
            </div>
        </section>
    );
}
