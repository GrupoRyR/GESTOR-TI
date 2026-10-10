/**
 * Mocks for products, documentation and search (boards B18–B20): Jhon Castro
 * (sees costs, edits docs), Leonardo Herrera without `ai.costs.view` or
 * `ai.docs.edit`, empty and filtered states. Entities come from ai-fixtures;
 * only page copy (notes, search snippets, repository sample) lives here.
 */
import type { DocSummary, Product, ProductDetail, ProductState } from '@/types/ai';
import type {
    AreaOption,
    DocListItem,
    DocsCreateProps,
    DocsEditProps,
    DocsHistoryProps,
    DocsIndexProps,
    DocsRepoProps,
    Highlighted,
    ProductsIndexProps,
    ProductsShowProps,
    RepoState,
    SearchIndexProps,
} from '@/types/pages/ai-knowledge';
import type { PageProps, Permission, SharedProps } from '@/types/shared';
import {
    AREAS,
    DIFF_MANUAL_6_7,
    DOC_MANUAL,
    DOC_VERSIONS_MANUAL,
    DOCS,
    MANUAL_V7,
    NOW_ISO,
    PEOPLE,
    productBySlug,
    PRODUCTS,
    requestByKey,
    VERSIONS_VISUALIZADOR,
} from './ai-fixtures';
import { SEGUREX, sharedFor, type MockUserKey, type MockVariant } from './shared';

// ---- shared pieces ------------------------------------------------------------

function without(key: MockUserKey, ...permissions: Permission[]): SharedProps {
    const removed: string[] = permissions;
    const shared = sharedFor(key);
    const user = shared.auth.user;
    return { ...shared, auth: { user: { ...user, permissions: user.permissions.filter((permission) => !removed.includes(permission)) } } };
}

/** Leonardo without the cost and the documentation permissions. */
const LEONARDO_LIMITED = without('leonardo', 'ai.costs.view', 'ai.costs.manage', 'ai.docs.edit');

const AREA_OPTIONS: AreaOption[] = Object.values(AREAS);

const hideCost = (product: Product): Product => ({ ...product, monthly_cost: null });

const stateCounts = (products: Product[]): Record<ProductState, number> => ({
    production: products.filter((product) => product.state === 'production').length,
    pilot: products.filter((product) => product.state === 'pilot').length,
    development: products.filter((product) => product.state === 'development').length,
    retired: products.filter((product) => product.state === 'retired').length,
});

// ---- Products -----------------------------------------------------------------

type ProductsPage = PageProps<ProductsIndexProps>;

const productsJhon: ProductsPage = {
    ...sharedFor('jhon'),
    products: PRODUCTS,
    filters: { q: null, company_id: null, area_id: null, state: null },
    state_counts: stateCounts(PRODUCTS),
    areas: AREA_OPTIONS,
};

const SEGUREX_PRODUCTS = PRODUCTS.filter((product) => product.company_id === SEGUREX.id);

const docById = (id: number): DocSummary => {
    const doc = DOCS.find((item) => item.id === id);
    if (!doc) throw new Error(`Mock doc not found: ${id}`);
    return doc;
};

const VISUALIZADOR: ProductDetail = {
    ...productBySlug('visualizador-cartera'),
    versions: VERSIONS_VISUALIZADOR,
    next_version: { tag: 'v3.2.0', request_key: 'GIA-139' },
    requests: [requestByKey('GIA-139'), requestByKey('GIA-151')],
    docs: [docById(1), docById(4), docById(6)],
    requests_from_button_this_month: 3,
};

type ShowPage = PageProps<ProductsShowProps>;

const productJhon: ShowPage = {
    ...sharedFor('jhon'),
    product: VISUALIZADOR,
    health: { enabled: false },
    incidents: [],
    hours_note: '4 h a la semana × 5 personas de Cartera',
    cost_note: 'Vercel, base de datos y dominio',
    request_notes: {
        'GIA-139': 'Proyecto P-07 · entrega proyectada 14 nov',
        'GIA-151': 'Diego Restrepo · llegó con el botón “Pedir mejora”',
    },
    now: '2026-10-07T09:53:00-05:00',
};

const CAMARAS: ProductDetail = {
    ...productBySlug('robotcamara'),
    versions: [{ tag: 'v0.9.3', date: '2026-09-15', summary: 'Primera versión de prueba en el equipo local de Gerencia.', request_keys: [], current: true }],
    next_version: null,
    requests: [],
    docs: [],
    requests_from_button_this_month: 0,
};

// ---- Docs ---------------------------------------------------------------------

const EDITORS: Record<number, DocListItem['updated_by']> = { 2: PEOPLE.diego, 5: PEOPLE.leonardo, 15: PEOPLE.leonardo };

const DOC_LIST: DocListItem[] = DOCS.map((doc) => ({ ...doc, updated_by: EDITORS[doc.id] ?? PEOPLE.jhon }));

type IndexPage = PageProps<DocsIndexProps>;

const docsIndex: IndexPage = { ...sharedFor('jhon'), docs: DOC_LIST, now: NOW_ISO };

type EditPage = PageProps<DocsEditProps>;

const docsEdit: EditPage = {
    ...sharedFor('jhon'),
    doc: DOC_MANUAL,
    tree: DOC_LIST,
    saved_at: '2026-10-07T09:52:00-05:00',
    version: 7,
    company_id: SEGUREX.id,
    now: '2026-10-07T09:53:00-05:00',
};

type CreatePage = PageProps<DocsCreateProps>;

const docsCreate: CreatePage = {
    ...sharedFor('jhon'),
    owners: [
        { kind: 'product', key: 'visualizador-cartera', name: 'Visualizador de cartera' },
        { kind: 'project', key: 'P-07', name: 'Visualizador de cartera' },
        { kind: 'product', key: 'toma-pedidos', name: 'Toma Pedidos SAP' },
        { kind: 'project', key: 'P-06', name: 'Toma Pedidos · descuentos' },
        { kind: 'product', key: 'portafolio', name: 'Portafolio de productos' },
        { kind: 'project', key: 'P-05', name: 'Portafolio · WooCommerce' },
        { kind: 'product', key: 'cuentas-por-pagar', name: 'Cuentas por pagar' },
        { kind: 'product', key: 'robotcamara', name: 'Cámaras' },
    ],
    defaults: { owner_kind: 'product', owner_key: 'visualizador-cartera' },
};

/** Version 6 of the manual: version 7 without the lines the diff adds. */
const MANUAL_V6 = MANUAL_V7.replace('## Ver la cartera por asesor', '## Ver la cartera de un asesor')
    .replace('Abre **Filtros**, elige el asesor y, si quieres,\nla zona. El total se recalcula solo.', 'Abre **Filtros** y elige el asesor.')
    .replace('| 31 a 90     | Requieren llamada del asesor   |\n', '')
    .replace('¿Falta algo? Usa el botón [Pedir mejora](#) dentro\nde la app.\n', '');

type HistoryPage = PageProps<DocsHistoryProps>;

const docsHistory: HistoryPage = {
    ...sharedFor('jhon'),
    doc: DOCS[3]!,
    versions: DOC_VERSIONS_MANUAL,
    compare: { version: 6, against: 7, lines: DIFF_MANUAL_6_7, markdown: MANUAL_V6 },
    now: '2026-10-07T09:53:00-05:00',
};

const README = `# Visualizador de cartera

Consulta en línea de la cartera de Segurex por cliente, edad de la deuda y asesor. Lee de SAP B1 y publica en Vercel.

## Cómo correrlo en tu equipo

1. Clona el repositorio y copia \`.env.example\` como \`.env.local\`.
2. Pide los valores a Jhon: están en la bóveda del Gestor, nunca en este archivo.
3. Instala y arranca con \`npm install\` y \`npm run dev\`.

## Variables de entorno

| Nombre | Para qué |
|---|---|
| \`DATABASE_URL\` | Base de datos con la copia de cartera |
| \`AZURE_AD_CLIENT_ID\` | Entrada con Microsoft |
| \`GESTOR_KIT_TOKEN\` | Botón "Pedir mejora" del Kit RYR |

## Más documentos

Arquitectura en [docs/arquitectura.md](#) y cómo publicar en [docs/despliegue.md](#).
`;

const REPO_SAMPLE: RepoState = {
    connected: true,
    sample: true,
    name: '[REPOSITORIO]',
    branch: 'main',
    fetched_at: '2026-10-07T06:40:00-05:00',
    files: [
        { path: 'README.md', kind: 'file', depth: 0, updated_at: '2026-09-22' },
        { path: 'CLAUDE.md', kind: 'file', depth: 0, updated_at: '2026-08-18' },
        { path: 'CHANGELOG.md', kind: 'file', depth: 0, updated_at: '2026-09-22' },
        { path: 'docs/', kind: 'dir', depth: 0, updated_at: null },
        { path: 'docs/arquitectura.md', kind: 'file', depth: 1, updated_at: '2026-08-18' },
        { path: 'docs/despliegue.md', kind: 'file', depth: 1, updated_at: '2026-08-18' },
        { path: 'docs/decisiones/0001-next-vercel.md', kind: 'file', depth: 1, updated_at: '2026-08-02' },
    ],
    selected: {
        path: 'README.md',
        markdown: README,
        updated_at: '2026-09-22',
        release: 'versión 3.1.0',
        github_url: '[REPOSITORIO]/blob/main/README.md',
    },
};

type RepoPage = PageProps<DocsRepoProps>;

const docsRepo: RepoPage = {
    ...sharedFor('jhon'),
    products: PRODUCTS.map((product) => ({ slug: product.slug, name: product.name })),
    product_slug: 'visualizador-cartera',
    repo: REPO_SAMPLE,
    now: NOW_ISO,
};

// ---- Search -------------------------------------------------------------------

/** Ranges of every whole-word occurrence of `words` (case-insensitive), like the server would send. */
function mark(text: string, words: string[]): Highlighted {
    const ranges: [number, number][] = [];
    const lower = text.toLowerCase();
    for (const word of words) {
        let index = lower.indexOf(word);
        while (index !== -1) {
            const before = index === 0 ? ' ' : lower[index - 1]!;
            const after = lower[index + word.length] ?? ' ';
            if (!/[\p{L}\p{N}]/u.test(before) && !/[\p{L}\p{N}]/u.test(after)) ranges.push([index, index + word.length]);
            index = lower.indexOf(word, index + word.length);
        }
    }
    return { text, highlights: ranges };
}

const WORDS = ['cartera', 'por', 'asesor'];

type SearchPage = PageProps<SearchIndexProps>;

const searchResults: SearchPage = {
    ...sharedFor('jhon'),
    query: { q: 'cartera por asesor', types: ['docs', 'requests', 'products'], company_id: null, area_id: null },
    results: {
        docs: [
            {
                doc: docById(4),
                snippet: mark('… ## Ver la cartera por asesor. Abre Filtros, elige el asesor y, si quieres, la zona. El total se recalcula solo …', WORDS),
            },
            {
                doc: docById(2),
                snippet: mark('… Criterio 2: al elegir un asesor se muestra solo su cartera, con los mismos totales que el informe de SAP …', ['cartera', 'asesor']),
            },
            {
                doc: docById(3),
                snippet: mark('… Decidimos consultar la cartera por asesor directo en SAP B1 en vez de copiarla cada noche …', WORDS),
            },
            {
                doc: docById(8),
                snippet: mark('… el pedido se bloquea si la cartera del cliente supera el cupo; el asesor no recibe aviso …', ['cartera', 'asesor']),
            },
        ],
        requests: ['GIA-139', 'GIA-148', 'GIA-121'].map((key) => {
            const request = requestByKey(key);
            return {
                request: { key: request.key, status: request.status, company_id: request.company_id, area: request.area },
                title: mark(request.title, ['cartera', 'asesor']),
            };
        }),
        products: [
            {
                product: (({ slug, name, company_id, state, version }) => ({ slug, name, company_id, state, version }))(productBySlug('visualizador-cartera')),
                name: mark('Visualizador de cartera', ['cartera']),
                snippet: mark('v3.1.0 · Cartera y Ventas · filtro por asesor llega en la v3.2.0', ['por', 'asesor']),
            },
        ],
    },
    type_counts: { docs: 4, requests: 3, products: 1 },
    company_counts: { 1: 8, 2: 0 },
    areas: AREA_OPTIONS,
    took_ms: 200,
    now: NOW_ISO,
};

// ---- variants -----------------------------------------------------------------

export const variants: Record<
    string,
    | MockVariant<ProductsPage>
    | MockVariant<ShowPage>
    | MockVariant<IndexPage>
    | MockVariant<EditPage>
    | MockVariant<CreatePage>
    | MockVariant<HistoryPage>
    | MockVariant<RepoPage>
    | MockVariant<SearchPage>
> = {
    // Products
    products: { page: 'Ai/Products/Index', label: 'Productos · todos (Jhon, Todas)', props: productsJhon },
    'products-segurex': {
        page: 'Ai/Products/Index',
        label: 'Productos · solo Segurex',
        props: { ...productsJhon, ...sharedFor('jhon', { currentCompanyId: SEGUREX.id }), products: SEGUREX_PRODUCTS, state_counts: stateCounts(SEGUREX_PRODUCTS) },
    },
    'products-no-costs': {
        page: 'Ai/Products/Index',
        label: 'Productos · sin permiso de costos (Leonardo)',
        props: { ...productsJhon, ...LEONARDO_LIMITED, products: PRODUCTS.map(hideCost) },
    },
    'products-empty': {
        page: 'Ai/Products/Index',
        label: 'Productos · filtro sin resultados',
        props: { ...productsJhon, products: [], filters: { q: null, company_id: null, area_id: null, state: 'retired' } },
    },
    product: { page: 'Ai/Products/Show', label: 'Producto · Visualizador de cartera', props: productJhon },
    'product-no-costs': {
        page: 'Ai/Products/Show',
        label: 'Producto · sin permiso de costos (Leonardo)',
        props: { ...productJhon, ...LEONARDO_LIMITED, product: { ...VISUALIZADOR, monthly_cost: null }, cost_note: null },
    },
    'product-monitoring': {
        page: 'Ai/Products/Show',
        label: 'Producto · con monitoreo activo (futuro)',
        props: {
            ...productJhon,
            health: { enabled: true, status: 'up', last_heartbeat_at: '2026-10-07T09:51:00-05:00', errors_24h: 0 },
            incidents: [
                { id: 1, title: 'No cargaba la cartera de Medellín', started_at: '2026-09-29T08:10:00-05:00', resolved_at: '2026-09-29T09:30:00-05:00', reported_by: PEOPLE.diego },
            ],
        },
    },
    'product-development': {
        page: 'Ai/Products/Show',
        label: 'Producto · Cámaras, en desarrollo y sin datos',
        props: { ...productJhon, product: CAMARAS, hours_note: null, cost_note: 'Corre en un equipo local: sin costo de hosting', request_notes: {} },
    },

    // Docs
    docs: { page: 'Ai/Docs/Index', label: 'Documentación · todos los documentos', props: docsIndex },
    'docs-empty': { page: 'Ai/Docs/Index', label: 'Documentación · sin documentos', props: { ...docsIndex, docs: [] } },
    'docs-edit': { page: 'Ai/Docs/Edit', label: 'Documento · manual de usuario (editar)', props: docsEdit },
    'docs-edit-readonly': {
        page: 'Ai/Docs/Edit',
        label: 'Documento · solo lectura (sin permiso de documentación)',
        props: { ...docsEdit, ...LEONARDO_LIMITED },
    },
    'docs-create': { page: 'Ai/Docs/Create', label: 'Nuevo documento · plantillas', props: docsCreate },
    'docs-create-no-permission': {
        page: 'Ai/Docs/Create',
        label: 'Nuevo documento · sin permiso (Leonardo)',
        props: { ...docsCreate, ...LEONARDO_LIMITED },
    },
    'docs-history': { page: 'Ai/Docs/History', label: 'Historial · versión 6 contra la actual', props: docsHistory },
    'docs-history-single': {
        page: 'Ai/Docs/History',
        label: 'Historial · una sola versión',
        props: { ...docsHistory, doc: docById(16), versions: [{ version: 1, summary: 'Primera versión.', author: PEOPLE.jhon, created_at: '2026-09-05T10:00:00-05:00', current: true }], compare: null },
    },
    'docs-repo': { page: 'Ai/Docs/Repo', label: 'Repositorio · muestra (se conecta en la etapa D)', props: docsRepo },
    'docs-repo-off': {
        page: 'Ai/Docs/Repo',
        label: 'Repositorio · sin conectar',
        props: { ...docsRepo, product_slug: 'robotcamara', repo: { connected: false } },
    },

    // Search
    search: { page: 'Ai/Search/Index', label: 'Buscar · “cartera por asesor”', props: searchResults },
    'search-start': {
        page: 'Ai/Search/Index',
        label: 'Buscar · sin escribir todavía',
        props: { ...searchResults, query: { q: '', types: ['docs', 'requests', 'products'], company_id: null, area_id: null }, results: null, type_counts: { docs: 0, requests: 0, products: 0 }, company_counts: {} },
    },
    'search-none': {
        page: 'Ai/Search/Index',
        label: 'Buscar · sin resultados',
        props: {
            ...searchResults,
            query: { q: 'nómina electrónica', types: ['docs', 'requests', 'products'], company_id: null, area_id: null },
            results: { docs: [], requests: [], products: [] },
            type_counts: { docs: 0, requests: 0, products: 0 },
            company_counts: { 1: 0, 2: 0 },
            took_ms: 100,
        },
    },
};
