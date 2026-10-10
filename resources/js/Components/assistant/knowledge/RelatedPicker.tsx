import { useId, useRef, useState, type KeyboardEvent } from 'react';
import { KnowledgeTypeIcon } from '@/Components/assistant/KnowledgeBadges';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import type { KnowledgeRelatedOption } from '@/types/pages/assistant-knowledge';

const MAX_RESULTS = 6;

export interface RelatedPickerProps {
    options: KnowledgeRelatedOption[];
    cardKeys: string[];
    productSlugs: string[];
    onChange: (next: { cardKeys: string[]; productSlugs: string[] }) => void;
    error?: string;
}

function optionId(option: KnowledgeRelatedOption): string {
    return option.kind === 'card' ? `card:${option.card.key}` : `product:${option.product.slug}`;
}

function optionLabel(option: KnowledgeRelatedOption): string {
    return option.kind === 'card' ? `${option.card.key} ${option.card.title}` : `${option.product.name} (producto)`;
}

/** Strip accents and case for the search. */
function fold(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

/**
 * "Relacionadas": chosen cards and products as removable chips, plus a
 * search ("Ficha o producto") whose results are buttons; ↓ / ↑ move between
 * them and Esc closes the search.
 */
export function RelatedPicker({ options, cardKeys, productSlugs, onChange, error }: RelatedPickerProps) {
    const baseId = useId();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);
    const resultsRef = useRef<HTMLUListElement>(null);
    const openerRef = useRef<HTMLButtonElement>(null);

    const chosen = options.filter((option) => (option.kind === 'card' ? cardKeys.includes(option.card.key) : productSlugs.includes(option.product.slug)));
    const needle = fold(query.trim());
    const results = options
        .filter((option) => !chosen.includes(option))
        .filter((option) => needle === '' || fold(optionLabel(option)).includes(needle) || (option.kind === 'product' && option.product.slug.includes(needle)))
        .slice(0, MAX_RESULTS);

    const toggle = (option: KnowledgeRelatedOption, add: boolean) => {
        if (option.kind === 'card') {
            const key = option.card.key;
            onChange({ cardKeys: add ? [...cardKeys, key] : cardKeys.filter((item) => item !== key), productSlugs });
        } else {
            const slug = option.product.slug;
            onChange({ cardKeys, productSlugs: add ? [...productSlugs, slug] : productSlugs.filter((item) => item !== slug) });
        }
    };

    const close = () => {
        setOpen(false);
        setQuery('');
        window.requestAnimationFrame(() => openerRef.current?.focus());
    };

    const focusResult = (index: number) => {
        const buttons = resultsRef.current?.querySelectorAll<HTMLButtonElement>('button');
        if (!buttons || buttons.length === 0) return;
        if (index < 0) inputRef.current?.focus();
        else buttons[Math.min(index, buttons.length - 1)]?.focus();
    };

    const onInputKey = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            focusResult(0);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const first = results[0];
            if (first) toggle(first, true);
        }
    };

    const onResultKey = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            focusResult(index + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            focusResult(index - 1);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close();
        }
    };

    return (
        <div role="group" aria-labelledby={`${baseId}-label`} className="flex min-w-0 flex-col gap-2">
            <span id={`${baseId}-label`} className={cx('text-[13px] font-semibold', error && 'text-status-failure')}>
                Relacionadas <span className="font-normal text-fg-muted">(opcional)</span>
            </span>
            <div className="flex flex-wrap items-center gap-2">
                {chosen.map((option) => (
                    <span
                        key={optionId(option)}
                        className="inline-flex h-9 max-w-full items-center gap-1.5 rounded-chip border border-line-strong bg-surface pr-0.5 pl-2.5 text-[13px] font-semibold"
                    >
                        {option.kind === 'card' ? (
                            <>
                                <span className="tabular">{option.card.key}</span>
                                <span className="truncate font-normal">{option.card.title}</span>
                            </>
                        ) : (
                            <>
                                <Icon name="code" size={14} className="shrink-0 text-fg-muted" />
                                <span className="truncate font-normal">{option.product.name}</span>
                            </>
                        )}
                        <button
                            type="button"
                            aria-label={`Quitar ${option.kind === 'card' ? option.card.key : option.product.name}`}
                            onClick={() => toggle(option, false)}
                            className="relative flex size-8 shrink-0 items-center justify-center rounded-chip text-fg-muted after:absolute after:-inset-1.5 hover:bg-subtle hover:text-fg"
                        >
                            <Icon name="x" size={14} />
                        </button>
                    </span>
                ))}
                {open ? null : (
                    <button
                        ref={openerRef}
                        type="button"
                        aria-expanded={false}
                        onClick={() => {
                            setOpen(true);
                            window.requestAnimationFrame(() => inputRef.current?.focus());
                        }}
                        className="inline-flex h-11 items-center gap-1.5 rounded-chip border border-dashed border-line-strong px-3 text-[13px] font-semibold text-fg-soft hover:border-fg-muted hover:text-fg"
                    >
                        <Icon name="plus" size={14} strokeWidth={2.4} />
                        Ficha o producto
                    </button>
                )}
            </div>
            {open ? (
                <div className="flex flex-col gap-1.5 rounded-control border border-line-strong bg-surface p-2">
                    <div className="flex items-center gap-1">
                        <label className="relative flex h-11 min-w-0 flex-1 items-center">
                            <span className="pointer-events-none absolute left-2.5 text-fg-muted">
                                <Icon name="search" size={16} />
                            </span>
                            <input
                                ref={inputRef}
                                type="search"
                                aria-label="Buscar ficha o producto para relacionar"
                                aria-controls={`${baseId}-results`}
                                placeholder="Clave, título o producto"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                onKeyDown={onInputKey}
                                className="h-full w-full min-w-0 rounded-chip bg-ground pr-2 pl-8 text-[15px] outline-none placeholder:text-fg-muted focus-visible:outline-2 focus-visible:outline-fg"
                            />
                        </label>
                        <button
                            type="button"
                            onClick={close}
                            className="inline-flex h-11 shrink-0 items-center rounded-control px-3 text-[13px] font-bold text-fg hover:bg-subtle"
                        >
                            Listo
                        </button>
                    </div>
                    <ul id={`${baseId}-results`} ref={resultsRef} aria-label="Resultados" className="flex flex-col">
                        {results.map((option, index) => (
                            <li key={optionId(option)}>
                                <button
                                    type="button"
                                    onClick={() => {
                                        toggle(option, true);
                                        inputRef.current?.focus();
                                    }}
                                    onKeyDown={(event) => onResultKey(event, index)}
                                    className="flex min-h-11 w-full items-center gap-2.5 rounded-chip px-2 py-1.5 text-left text-sm hover:bg-subtle focus-visible:-outline-offset-2"
                                >
                                    {option.kind === 'card' ? (
                                        <KnowledgeTypeIcon type={option.card.type} size="sm" />
                                    ) : (
                                        <span aria-hidden="true" className="flex size-8 shrink-0 items-center justify-center rounded-chip bg-subtle text-fg-soft">
                                            <Icon name="code" size={16} />
                                        </span>
                                    )}
                                    <span className="flex min-w-0 flex-col">
                                        <span className="truncate font-semibold">{option.kind === 'card' ? option.card.title : option.product.name}</span>
                                        <span className="tabular truncate text-xs text-fg-muted">
                                            {option.kind === 'card' ? option.card.key : `Producto · ${option.product.slug}`}
                                        </span>
                                    </span>
                                    <span className="ml-auto shrink-0 text-fg-muted" aria-hidden="true">
                                        <Icon name="plus" size={16} />
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                    <p role="status" aria-live="polite" className="px-2 pb-1 text-xs text-fg-muted">
                        {results.length === 0 ? 'Nada coincide. Prueba con la clave (K-031) o el nombre del producto.' : results.length === 1 ? "1 resultado" : `${results.length} resultados`}
                    </p>
                </div>
            ) : null}
            {error ? <p className="text-[13px] font-semibold text-status-failure">{error}</p> : null}
        </div>
    );
}
