import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { filterCatalog, flattenVariantIds, stepVariant, type Catalog, type CatalogWarning } from '../lib/catalog';

interface SidebarProps {
    catalog: Catalog;
    selectedPage: string;
    selectedVariant: string | null;
    onSelectVariant: (id: string) => void;
    onSelectPage: (name: string) => void;
}

function warningText(warning: CatalogWarning): string {
    switch (warning.kind) {
        case 'invalid-variant':
            return `${warning.variant}: el ejemplo no tiene page, label y props válidos.`;
        case 'variant-without-page':
            return `${warning.variant} apunta a ${warning.page}, que no tiene archivo en Pages.`;
        case 'page-without-variants':
            return `${warning.page} no tiene ejemplos en resources/js/mocks.`;
    }
}

export function Sidebar({ catalog, selectedPage, selectedVariant, onSelectVariant, onSelectPage }: SidebarProps) {
    const [query, setQuery] = useState('');
    const [expanded, setExpanded] = useState<Set<string>>(() => new Set([selectedPage]));
    const listRef = useRef<HTMLDivElement>(null);
    const searching = query.trim() !== '';

    const filtered = useMemo(() => filterCatalog(catalog, query), [catalog, query]);
    // ↑/↓ walk every matching example; the page holding the selection opens itself.
    const visibleIds = useMemo(() => flattenVariantIds(filtered), [filtered]);

    useEffect(() => {
        setExpanded((previous) => (previous.has(selectedPage) ? previous : new Set(previous).add(selectedPage)));
    }, [selectedPage]);

    // After ↑/↓ from inside the list, move focus to the new entry. Its page may
    // only open on the next render, so retry when `expanded` changes.
    const focusPending = useRef(false);
    const scrolledTo = useRef<string | null>(null);
    useEffect(() => {
        if (selectedVariant === null) return;
        const button = listRef.current?.querySelector<HTMLElement>(`[data-variant-id="${CSS.escape(selectedVariant)}"]`);
        if (button === null || button === undefined) return;
        if (scrolledTo.current !== selectedVariant) {
            scrolledTo.current = selectedVariant;
            button.scrollIntoView({ block: 'nearest' });
        }
        if (focusPending.current) {
            focusPending.current = false;
            button.focus();
        }
    }, [selectedVariant, expanded]);

    const onKeyDown = (event: KeyboardEvent<HTMLElement>) => {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
        const next = stepVariant(visibleIds, selectedVariant, event.key === 'ArrowDown' ? 1 : -1);
        if (next === null) return;
        event.preventDefault();
        focusPending.current = listRef.current?.contains(document.activeElement) ?? false;
        onSelectVariant(next);
    };

    const togglePage = (name: string) => {
        setExpanded((previous) => {
            const next = new Set(previous);
            if (next.has(name)) next.delete(name);
            else next.add(name);
            return next;
        });
    };

    return (
        <div className="flex h-full min-h-0 flex-col" onKeyDown={onKeyDown}>
            <div className="border-b border-line px-4 pt-4 pb-3">
                <p className="font-display text-[19px] leading-tight font-bold text-fg">Galería Gestor TI</p>
                <p className="mt-0.5 text-[13px] text-fg-muted">Pantallas con datos de ejemplo, sin servidor.</p>
                <label htmlFor="gallery-search" className="sr-only">
                    Buscar pantallas y ejemplos
                </label>
                <div className="relative mt-3">
                    <Icon name="search" size={18} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-fg-muted" />
                    <input
                        id="gallery-search"
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Buscar pantalla o ejemplo"
                        autoComplete="off"
                        className="h-11 w-full rounded-control border border-line-strong bg-surface pr-3 pl-10 text-sm text-fg placeholder:text-fg-muted"
                    />
                </div>
                <p className="mt-2 text-[12px] text-fg-muted">Usa ↑ y ↓ para pasar de un ejemplo a otro.</p>
            </div>

            <div ref={listRef} className="min-h-0 flex-1 overflow-y-auto px-2 py-3">
                <nav aria-label="Pantallas">
                    {filtered.groups.length === 0 ? (
                        <p className="px-3 py-6 text-sm text-fg-muted">Nada coincide con «{query.trim()}».</p>
                    ) : null}
                    {filtered.groups.map((group) => (
                        <section key={group.key} aria-labelledby={`group-${group.key}`} className="mb-4">
                            <h2 id={`group-${group.key}`} className="px-3 pb-1 text-[13px] font-bold text-fg-muted">
                                {group.label}
                            </h2>
                            <ul>
                                {group.pages.map((page) => {
                                    const open = searching || expanded.has(page.name);
                                    const listId = `variants-${page.name.replace(/[^a-z0-9]/gi, '-')}`;
                                    const isSelectedPage = page.name === selectedPage;
                                    return (
                                        <li key={page.name}>
                                            {page.variants.length > 0 ? (
                                                <button
                                                    type="button"
                                                    aria-expanded={open}
                                                    aria-controls={listId}
                                                    onClick={() => togglePage(page.name)}
                                                    className={cx(
                                                        'flex min-h-10 w-full items-center gap-2 rounded-chip px-3 text-left text-sm font-semibold hover:bg-subtle',
                                                        isSelectedPage ? 'text-fg' : 'text-fg-soft',
                                                    )}
                                                >
                                                    <Icon
                                                        name="chevron-right"
                                                        size={16}
                                                        className={cx('text-fg-muted transition-transform duration-150', open && 'rotate-90')}
                                                    />
                                                    <span className="min-w-0 flex-1 truncate">{page.title}</span>
                                                    {!page.hasFile ? (
                                                        <Icon name="alert-triangle" size={16} label="Sin archivo de página" className="text-status-maintenance" />
                                                    ) : null}
                                                    <span className="tabular text-[12px] font-semibold text-fg-muted">{page.variants.length}</span>
                                                </button>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() => onSelectPage(page.name)}
                                                    aria-current={isSelectedPage ? 'page' : undefined}
                                                    className="flex min-h-10 w-full items-center gap-2 rounded-chip px-3 text-left text-sm font-semibold text-fg-muted hover:bg-subtle aria-[current=page]:bg-subtle"
                                                >
                                                    <Icon name="alert-triangle" size={16} className="text-status-maintenance" />
                                                    <span className="min-w-0 flex-1 truncate">{page.title}</span>
                                                    <span className="text-[12px]">sin ejemplos</span>
                                                </button>
                                            )}
                                            {page.variants.length > 0 && open ? (
                                                <ul id={listId} className="mb-1 ml-[18px] border-l border-line pl-2">
                                                    {page.variants.map((variant) => {
                                                        const selected = variant.id === selectedVariant;
                                                        return (
                                                            <li key={variant.id}>
                                                                <button
                                                                    type="button"
                                                                    data-variant-id={variant.id}
                                                                    aria-current={selected ? 'page' : undefined}
                                                                    onClick={() => onSelectVariant(variant.id)}
                                                                    className={cx(
                                                                        'relative flex min-h-9 w-full items-center rounded-chip px-3 py-1.5 text-left text-[13px] leading-snug',
                                                                        selected
                                                                            ? 'bg-fg font-bold text-on-fg'
                                                                            : 'text-fg-soft hover:bg-subtle hover:text-fg',
                                                                    )}
                                                                >
                                                                    {variant.label}
                                                                </button>
                                                            </li>
                                                        );
                                                    })}
                                                </ul>
                                            ) : null}
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    ))}
                </nav>

                {catalog.warnings.length > 0 ? (
                    <section aria-labelledby="gallery-warnings" className="mx-1 mt-2 rounded-control bg-status-maintenance-bg p-3">
                        <h2 id="gallery-warnings" className="flex items-center gap-1.5 text-[13px] font-bold text-status-maintenance">
                            <Icon name="alert-triangle" size={16} />
                            Avisos ({catalog.warnings.length})
                        </h2>
                        <ul className="mt-1.5 space-y-1.5 text-[12px] leading-snug text-fg-soft">
                            {catalog.warnings.map((warning) => (
                                <li key={`${warning.kind}-${'variant' in warning ? warning.variant : warning.page}`}>{warningText(warning)}</li>
                            ))}
                        </ul>
                    </section>
                ) : null}
            </div>
        </div>
    );
}
