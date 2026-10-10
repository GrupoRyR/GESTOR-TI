/**
 * Gallery shell: lists every page × mock variant (found by glob) and shows
 * the selection inside `frame.html`, sized to a device. All state lives in
 * the URL hash so a link reproduces the exact view.
 */
import './shims/route';
import '../resources/css/app.css';
import './shell/shell.css';
import { StrictMode, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Icon } from '@/Components/ui/Icon';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { BRAND_COMPANIES, catalog } from './catalogData';
import { isGuestProps } from './lib/brandOverride';
import { DESIGN_SYSTEM_PAGE, resolveSelection, typesFileFor } from './lib/catalog';
import {
    BRANDS,
    frameHash,
    parseHash,
    serializeHash,
    THEMES,
    VIEWPORTS,
    type BrandChoice,
    type GalleryState,
    type ThemeChoice,
    type Viewport,
} from './lib/hashState';
import { describeNavigation, isNavigationMessage } from './lib/navigation';
import { PropsPanel } from './shell/PropsPanel';
import { Sidebar } from './shell/Sidebar';
import { Stage } from './shell/Stage';

const VIEWPORT_OPTIONS = (Object.keys(VIEWPORTS) as Viewport[]).map((value) => ({ value, label: VIEWPORTS[value].label }));
const THEME_OPTIONS = (Object.keys(THEMES) as ThemeChoice[]).map((value) => ({ value, label: THEMES[value] }));
const BRAND_OPTIONS = (Object.keys(BRANDS) as BrandChoice[]).map((value) => ({ value, label: BRANDS[value] }));

function useHashState() {
    const [state, setState] = useState<GalleryState>(() => parseHash(window.location.hash));

    useEffect(() => {
        const onHashChange = () => setState(parseHash(window.location.hash));
        window.addEventListener('hashchange', onHashChange);
        return () => window.removeEventListener('hashchange', onHashChange);
    }, []);

    const update = useCallback((patch: Partial<GalleryState>) => {
        setState((previous) => {
            const next = { ...previous, ...patch };
            window.history.replaceState(null, '', `#${serializeHash(next)}`);
            return next;
        });
    }, []);

    return [state, update] as const;
}

function useMediaQuery(query: string): boolean {
    const [matches, setMatches] = useState(() => window.matchMedia(query).matches);
    useEffect(() => {
        const list = window.matchMedia(query);
        const onChange = () => setMatches(list.matches);
        list.addEventListener('change', onChange);
        return () => list.removeEventListener('change', onChange);
    }, [query]);
    return matches;
}

function useNavigationToast() {
    const [toast, setToast] = useState<{ id: number; text: string } | null>(null);
    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (event.origin !== window.location.origin || !isNavigationMessage(event.data)) return;
            setToast({ id: Date.now(), text: describeNavigation(event.data) });
        };
        window.addEventListener('message', onMessage);
        return () => window.removeEventListener('message', onMessage);
    }, []);
    useEffect(() => {
        if (toast === null) return;
        const timer = window.setTimeout(() => setToast(null), 3200);
        return () => window.clearTimeout(timer);
    }, [toast]);
    return toast;
}

function Gallery() {
    const [state, update] = useHashState();
    const [drawerOpen, setDrawerOpen] = useState(false);
    const toast = useNavigationToast();
    const drawerRef = useRef<HTMLDivElement>(null);
    // One sidebar instance only (it carries ids): fixed column from 1024 px, drawer below.
    const wide = useMediaQuery('(min-width: 1024px)');

    const selection = resolveSelection(catalog, state);
    const variantId = selection.variant?.id ?? selection.missingVariant ?? null;
    const resolved: GalleryState = { ...state, page: selection.page, variant: variantId };
    const hashForFrame = frameHash(resolved);

    // Write the resolved selection back so the link always names it.
    useEffect(() => {
        if (state.page !== resolved.page || state.variant !== resolved.variant) {
            update({ page: resolved.page, variant: resolved.variant });
        }
    }, [state.page, state.variant, resolved.page, resolved.variant, update]);

    // The shell follows the chosen theme too, so light and dark can be judged side by side with the chrome.
    useEffect(() => {
        const root = document.documentElement;
        if (state.theme === 'system') delete root.dataset.theme;
        else root.dataset.theme = state.theme;
    }, [state.theme]);

    useEffect(() => {
        if (!drawerOpen) return;
        drawerRef.current?.querySelector<HTMLInputElement>('#gallery-search')?.focus();
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setDrawerOpen(false);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [drawerOpen]);

    const selectVariant = useCallback(
        (id: string) => {
            const page = id.startsWith(`${DESIGN_SYSTEM_PAGE}:`) ? DESIGN_SYSTEM_PAGE : null;
            update({ variant: id, page });
            setDrawerOpen(false);
        },
        [update],
    );
    const selectPage = useCallback(
        (name: string) => {
            update({ page: name, variant: null });
            setDrawerOpen(false);
        },
        [update],
    );

    const props = selection.variant?.props ?? null;
    const guest = isGuestProps(props);
    const isDesignSystem = selection.page === DESIGN_SYSTEM_PAGE;
    const json = useMemo(() => JSON.stringify(props ?? {}, null, 2), [props]);
    const brandLabel = BRANDS[state.brand];
    const brandNote =
        state.brand === 'example' || isDesignSystem
            ? null
            : guest
              ? 'Página de invitado: la marca escogida no aplica.'
              : `Solo en la vista: current_company_id cambia a ${BRAND_COMPANIES.find((company) => company.slug === state.brand)?.id ?? '?'} (${brandLabel}).`;
    const typesFile = typesFileFor(selection.page);
    const frameTitle = `Vista previa: ${selection.page}${selection.variant !== null ? `, ${selection.variant.label}` : ''}`;

    const sidebar = (
        <Sidebar
            catalog={catalog}
            selectedPage={selection.page}
            selectedVariant={variantId}
            onSelectVariant={selectVariant}
            onSelectPage={selectPage}
        />
    );

    return (
        <div className="flex h-dvh overflow-hidden bg-ground text-fg">
            {wide ? (
                <aside aria-label="Galería" className="w-[300px] shrink-0 border-r border-line bg-surface">
                    {sidebar}
                </aside>
            ) : null}

            {drawerOpen && !wide ? (
                <div className="fixed inset-0 z-40">
                    <button
                        type="button"
                        aria-label="Cerrar la lista de pantallas"
                        className="absolute inset-0 bg-black/40"
                        onClick={() => setDrawerOpen(false)}
                    />
                    <div
                        ref={drawerRef}
                        role="dialog"
                        aria-modal="true"
                        aria-label="Pantallas"
                        className="absolute inset-y-0 left-0 w-[min(320px,88vw)] bg-surface shadow-card"
                    >
                        {sidebar}
                    </div>
                </div>
            ) : null}

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line bg-surface px-3 py-2 sm:px-4">
                    {wide ? null : (
                        <button
                            type="button"
                            onClick={() => setDrawerOpen(true)}
                            aria-expanded={drawerOpen}
                            className="inline-flex h-[38px] items-center gap-2 rounded-chip border border-line-strong px-3 text-[13px] font-semibold text-fg"
                        >
                            <Icon name="list" size={18} />
                            Pantallas
                        </button>
                    )}
                    <SegmentedControl label="Tamaño de pantalla" size="sm" options={VIEWPORT_OPTIONS} value={state.vp} onChange={(vp) => update({ vp })} />
                    <SegmentedControl label="Tema" size="sm" options={THEME_OPTIONS} value={state.theme} onChange={(theme) => update({ theme })} />
                    <label className="flex items-center gap-2 text-[13px] font-semibold text-fg-soft">
                        Marca
                        <span className="relative">
                            <select
                                value={state.brand}
                                onChange={(event) => update({ brand: event.target.value as BrandChoice })}
                                className="h-[38px] appearance-none rounded-chip border border-line-strong bg-surface pr-8 pl-3 text-[13px] font-semibold text-fg"
                            >
                                {BRAND_OPTIONS.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <Icon name="chevron-down" size={16} className="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-fg-muted" />
                        </span>
                    </label>
                    <div className="ml-auto flex items-center gap-2">
                        <button
                            type="button"
                            aria-pressed={state.props}
                            onClick={() => update({ props: !state.props })}
                            className="inline-flex h-[38px] items-center gap-1.5 rounded-chip border border-line-strong px-3 text-[13px] font-semibold text-fg hover:bg-subtle aria-pressed:border-fg aria-pressed:bg-fg aria-pressed:text-on-fg"
                        >
                            <Icon name="code" size={16} />
                            Ver props
                        </button>
                        <a
                            href={`frame.html#${hashForFrame}`}
                            target="_blank"
                            rel="noopener"
                            className="inline-flex h-[38px] items-center gap-1.5 rounded-chip px-3 text-[13px] font-semibold text-fg-soft hover:bg-subtle hover:text-fg"
                        >
                            <Icon name="external" size={16} />
                            Abrir solo
                            <span className="sr-only">(se abre en otra pestaña)</span>
                        </a>
                    </div>
                </header>

                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-b border-line bg-surface px-4 py-2.5">
                    <h1 className="font-mono text-[15px] font-bold text-fg">
                        {isDesignSystem ? 'ui-preview/DesignSystem.tsx' : selection.page}
                    </h1>
                    <p className="text-sm text-fg-soft">
                        {selection.variant?.label ?? (selection.missingVariant !== undefined ? 'Ejemplo inexistente' : 'Sin ejemplos')}
                    </p>
                    {typesFile !== null ? (
                        <p className="text-[13px] text-fg-muted lg:ml-auto">
                            Tipos: <code className="font-mono text-fg-soft">{typesFile}</code>
                        </p>
                    ) : null}
                </div>

                <div className="flex min-h-0 flex-1 flex-col lg:flex-row">
                    <Stage viewport={state.vp} frameHash={hashForFrame} title={frameTitle} />
                    {state.props ? (
                        <PropsPanel
                            json={isDesignSystem ? '// El sistema de diseño usa datos internos; no recibe props.' : json}
                            brandNote={brandNote}
                            onClose={() => update({ props: false })}
                        />
                    ) : null}
                </div>
            </div>

            <div aria-live="polite" role="status" className="pointer-events-none fixed bottom-5 left-1/2 z-50 -translate-x-1/2">
                {toast !== null ? (
                    <p
                        key={toast.id}
                        className="gallery-toast flex items-center gap-2 rounded-control bg-fg px-4 py-2.5 text-[13px] font-semibold text-on-fg shadow-card"
                    >
                        <Icon name="info" size={16} />
                        Navegación simulada: {toast.text}
                    </p>
                ) : null}
            </div>
        </div>
    );
}

const container = document.getElementById('root');
if (!container) {
    throw new Error('Missing #root element in ui-preview/index.html');
}

createRoot(container).render(
    <StrictMode>
        <Gallery />
    </StrictMode>,
);
