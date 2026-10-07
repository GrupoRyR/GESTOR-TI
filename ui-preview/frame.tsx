/**
 * Renders exactly one page (or a design-system view) from the hash:
 * `frame.html#page=…&variant=…&theme=…&brand=…`. The gallery shell embeds it
 * in an iframe sized to a device so real CSS breakpoints apply; it also works
 * on its own ("Abrir solo").
 */
import './shims/route';
import '../resources/css/app.css';
import { Component, lazy, StrictMode, Suspense, useEffect, useState, type ComponentType, type ErrorInfo, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { BRAND_COMPANIES, catalog, pageLoaderByName } from './catalogData';
import { AdminLayoutDemo, AppLayoutDemo, DesignSystem, GuestLayoutDemo } from './DesignSystem';
import { applyBrandOverride } from './lib/brandOverride';
import { DESIGN_SYSTEM_PAGE, resolveSelection } from './lib/catalog';
import { parseHash, type GalleryState } from './lib/hashState';
import { navigationMessage } from './lib/navigation';

const DESIGN_SYSTEM_VIEWS: Record<string, ComponentType> = {
    components: DesignSystem,
    app: AppLayoutDemo,
    admin: AdminLayoutDemo,
    guest: GuestLayoutDemo,
};

// ---- error card -----------------------------------------------------------

function ErrorCard({ title, message, detail }: { title: string; message: string; detail?: string }) {
    return (
        <main className="flex min-h-dvh items-start justify-center bg-ground p-4 sm:p-10">
            <section
                role="alert"
                className="w-full max-w-xl rounded-card border border-status-failure-line bg-surface p-6 text-fg shadow-card"
            >
                <p className="text-[13px] font-bold text-status-failure">Vista previa</p>
                <h1 className="mt-1 font-display text-2xl font-bold">{title}</h1>
                <p className="mt-3 rounded-control bg-status-failure-bg px-3 py-2 font-mono text-[13px] break-words text-status-failure">
                    {message}
                </p>
                {detail !== undefined ? <p className="mt-3 text-sm text-fg-muted">{detail}</p> : null}
            </section>
        </main>
    );
}

class PageErrorBoundary extends Component<{ children: ReactNode; page: string }, { error: Error | null }> {
    state: { error: Error | null } = { error: null };

    static getDerivedStateFromError(error: unknown) {
        return { error: error instanceof Error ? error : new Error(String(error)) };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        console.error('[ui-preview] page crashed', error, info.componentStack);
    }

    render() {
        if (this.state.error !== null) {
            return (
                <ErrorCard
                    title="Esta pantalla falló al mostrarse"
                    message={this.state.error.message}
                    detail={`Revisa ${this.props.page} y que las props del ejemplo cumplan su tipo. El detalle está en la consola.`}
                />
            );
        }
        return this.props.children;
    }
}

// ---- page loading ---------------------------------------------------------

const lazyPages = new Map<string, ComponentType<Record<string, unknown>>>();

function lazyPage(name: string, loader: () => Promise<{ default: ComponentType<Record<string, unknown>> }>) {
    let page = lazyPages.get(name);
    if (page === undefined) {
        page = lazy(loader);
        lazyPages.set(name, page);
    }
    return page;
}

function Loading() {
    return (
        <p role="status" className="p-6 text-sm text-fg-muted">
            Cargando la pantalla…
        </p>
    );
}

function FramedPage({ state }: { state: GalleryState }) {
    const selection = resolveSelection(catalog, state);

    if (selection.missingVariant !== undefined) {
        return (
            <ErrorCard
                title="No existe ese ejemplo"
                message={`variant=${selection.missingVariant}`}
                detail="Ningún archivo de resources/js/mocks define este ejemplo. Escoge otro en la galería."
            />
        );
    }

    if (selection.page === DESIGN_SYSTEM_PAGE) {
        const View = DESIGN_SYSTEM_VIEWS[selection.variant?.key ?? 'components'] ?? DesignSystem;
        return <View />;
    }

    const loader = pageLoaderByName.get(selection.page);
    if (loader === undefined) {
        return (
            <ErrorCard
                title="La página no existe"
                message={`resources/js/Pages/${selection.page}.tsx`}
                detail="Un ejemplo apunta a esta página, pero el archivo no está. Revisa el campo «page» del ejemplo."
            />
        );
    }

    if (selection.variant === null) {
        return (
            <ErrorCard
                title="Esta página no tiene ejemplos"
                message={selection.page}
                detail="Agrega al menos un ejemplo en resources/js/mocks para verla en la galería."
            />
        );
    }

    const Page = lazyPage(selection.page, loader);
    const props = applyBrandOverride(selection.variant.props as Record<string, unknown>, state.brand, BRAND_COMPANIES);
    return <Page {...props} />;
}

// ---- frame ----------------------------------------------------------------

function useHashState(): GalleryState {
    const [state, setState] = useState(() => parseHash(window.location.hash));
    useEffect(() => {
        const onHashChange = () => setState(parseHash(window.location.hash));
        window.addEventListener('hashchange', onHashChange);
        return () => window.removeEventListener('hashchange', onHashChange);
    }, []);
    return state;
}

/** Plain anchors and native forms must not move the frame away from the page. */
function useNavigationGuard() {
    useEffect(() => {
        const post = (method: string, url: string) => {
            console.info('[ui-preview] navegación simulada', method.toUpperCase(), url);
            if (window.parent !== window) window.parent.postMessage(navigationMessage(method, url), '*');
        };
        const onClick = (event: MouseEvent) => {
            if (event.defaultPrevented || !(event.target instanceof Element)) return;
            const anchor = event.target.closest('a[href]');
            if (anchor === null) return;
            event.preventDefault();
            post('get', anchor.getAttribute('href') ?? '');
        };
        const onSubmit = (event: SubmitEvent) => {
            if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) return;
            event.preventDefault();
            post(event.target.method || 'get', event.target.getAttribute('action') ?? '');
        };
        // Bubble phase on window: React handlers (Inertia Link shim) run first and mark the event handled.
        window.addEventListener('click', onClick);
        window.addEventListener('submit', onSubmit);
        return () => {
            window.removeEventListener('click', onClick);
            window.removeEventListener('submit', onSubmit);
        };
    }, []);
}

function Frame() {
    const state = useHashState();
    useNavigationGuard();
    const key = `${state.page ?? ''}|${state.variant ?? ''}|${state.brand}`;

    useEffect(() => {
        const root = document.documentElement;
        if (state.theme === 'system') delete root.dataset.theme;
        else root.dataset.theme = state.theme;
    }, [state.theme]);

    useEffect(() => {
        window.scrollTo(0, 0);
    }, [key]);

    return (
        <PageErrorBoundary key={key} page={state.page ?? DESIGN_SYSTEM_PAGE}>
            <Suspense fallback={<Loading />}>
                <FramedPage state={state} />
            </Suspense>
        </PageErrorBoundary>
    );
}

const container = document.getElementById('root');
if (!container) {
    throw new Error('Missing #root element in ui-preview/frame.html');
}

createRoot(container).render(
    <StrictMode>
        <Frame />
    </StrictMode>,
);
