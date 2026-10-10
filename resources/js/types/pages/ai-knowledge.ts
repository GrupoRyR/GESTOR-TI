/**
 * Gestor IA · products, documentation and search (boards B18–B20). Desktop-
 * first; every page also works at 390 px (tables become cards, the editor
 * stacks).
 *
 * Inertia components and their props:
 * - `Ai/Products/Index`  ProductsIndexProps  B-Products (GestorLayout tab "products")
 * - `Ai/Products/Show`   ProductsShowProps   B-ProductDetail + C-IncidentForm drawer (tab "products")
 * - `Ai/Products/Create` ProductsCreateProps C-ProductForm, "Nuevo producto" (tab "products")
 * - `Ai/Products/Edit`   ProductsEditProps   C-ProductForm, "Editar producto · …" (tab "products")
 * - `Ai/Docs/Index`      DocsIndexProps      doc tree landing (tab "docs")
 * - `Ai/Docs/Edit`       DocsEditProps       B-Docs (tree, Markdown editor + preview, autosave)
 * - `Ai/Docs/Create`     DocsCreateProps     B-DocsTemplates (template picker)
 * - `Ai/Docs/History`    DocsHistoryProps    B-DocsHistory (versions + diff + restore)
 * - `Ai/Docs/Repo`       DocsRepoProps       B-DocsRepo (read-only files from GitHub; connects in Stage D)
 * - `Ai/Search/Index`    SearchIndexProps    B-Search (docs, requests, products)
 *
 * Ziggy routes used:
 * - `ai.products.index`   GET    query `{ q?, company_id?, area_id?, state?: ProductState }` (null filters omitted)
 * - `ai.products.show`    GET    `{ product: slug }`                       row link, search result, doc owner link
 * - `ai.products.create`  GET    —                                         "Agregar producto" → `Ai/Products/Create`
 * - `ai.products.store`   POST   body `ProductFormData`                    "Crear producto" (redirects to `ai.products.show`)
 * - `ai.products.edit`    GET    `{ product: slug }`                       "Editar ficha" → `Ai/Products/Edit`
 * - `ai.products.update`  PUT    `{ product: slug }` body `ProductFormData` "Guardar cambios" (redirects to `ai.products.show`
 *                                         with the new slug when it changed). Every change is audited (who and when).
 * - `ai.products.incidents.create` GET `{ product: slug }`                  Deep link (Telegram, bookmarks): renders
 *                                         `Ai/Products/Show` with `incident_form.open = true`. On the page itself
 *                                         "Registrar incidente" opens the same drawer client-side, without a visit.
 * - `ai.products.incidents.store`  POST `{ product: slug }` body `IncidentStorePayload`  "Registrar incidente" in the
 *                                         drawer; back to `ai.products.show`. With validation errors the server renders
 *                                         Show again with `errors` and `incident_form.open = true` (the drawer reopens).
 * - `ai.requests.create`  GET    query `{ producto: slug }`                "Pedir mejora" (the wizard opens with the product chosen)
 * - `ai.requests.show`    GET    `{ request: key }`                        product requests, version notes, search results
 * - `ai.inbox.index`      GET    query `{ product: slug }`                 "Ver todas" the open requests of a product
 * - `ai.docs.index`       GET    —                                         tab, back links
 * - `ai.docs.create`      GET    query `{ owner_kind?: 'project' | 'product', owner_key? }`  "Nuevo documento"
 * - `ai.docs.store`       POST   body `DocStorePayload`                    "Crear con esta plantilla" (redirects to `ai.docs.edit`)
 * - `ai.docs.edit`        GET    `{ doc: id }`                             tree item, product docs, search result
 * - `ai.docs.update`      PUT    `{ doc: id }` body `{ markdown: string }` autosave, debounced 1,5 s after typing;
 *                                         partial reload `only: ['saved_at', 'version']`, preserveScroll/State
 * - `ai.docs.publish`     POST   `{ doc: id }` body `{ summary: string }`  "Publicar versión" (adds a restorable version)
 * - `ai.docs.history`     GET    `{ doc: id }` query `{ version?: number }`  "Ver historial"; choosing a version is a
 *                                         partial reload `only: ['compare']` with `{ version }`
 * - `ai.docs.restore`     POST   `{ doc: id, version: number }`            "Restaurar esta versión" (after ConfirmDialog;
 *                                         creates a new version, deletes nothing)
 * - `ai.docs.repo`        GET    query `{ product?: slug, path?: string }` "Documentos del repositorio"; choosing a file
 *                                         is a partial reload `only: ['repo']` with `{ product, path }`
 * - `ai.search`           GET    query `{ q, type?: 'docs,requests' (comma list of SearchType; omitted = all),
 *                                         company_id?, area_id? }`
 * - `ai.projects.show`    GET    `{ project: key }`                        doc owner link when the owner is a project
 *
 * Permissions:
 * - `ai.manage`      every page here (IT staff), including creating and editing products and registering incidents.
 *                    The product form renders NoPermission without it; the server must still refuse the writes.
 * - `ai.docs.edit`   editing, creating, publishing and restoring docs. Without it `Ai/Docs/Edit` is read-only
 *                    (preview only) and "Nuevo documento" / "Restaurar" are hidden.
 * - `ai.costs.view`  `Product.monthly_cost` (null without it; the column and KPI show a lock hint instead).
 * - Search returns only what the viewer's role may see (server-side filtering).
 */
import type { HighlightRange, SearchType } from '@/Components/ai/search/highlight';
import type { FormErrors } from '@/types/shared';
import type {
    AreaRef,
    DiffLine,
    Doc,
    DocSummary,
    DocTemplate,
    DocVersion,
    IncidentImpact,
    PersonRef,
    Product,
    ProductDetail,
    ProductKind,
    ProductState,
    RequestSummary,
} from '@/types/ai';

export type { HighlightRange, SearchType };

export type AreaOption = Pick<AreaRef, 'id' | 'name' | 'company_id'>;

// ---- Products ---------------------------------------------------------------

export interface ProductFilters {
    /** Free text on name, stack or owner. */
    q: string | null;
    /** Only used in "Todas". */
    company_id: number | null;
    area_id: number | null;
    state: ProductState | null;
}

export interface ProductsIndexProps {
    /** After the filters. `monthly_cost` is null without `ai.costs.view`. */
    products: Product[];
    filters: ProductFilters;
    /** Count per state with the other filters applied (labels "Producción · 3"). */
    state_counts: Record<ProductState, number>;
    /** Areas for the "Área" filter. */
    areas: AreaOption[];
}

/** Monitoring arrives with the Kit RYR heartbeat; Stage B always sends `{ enabled: false }`. */
export type ProductHealth =
    | { enabled: false }
    | {
          enabled: true;
          status: 'up' | 'degraded' | 'down';
          last_heartbeat_at: string;
          errors_24h: number;
      };

export interface ProductIncident {
    id: number;
    title: string;
    started_at: string;
    resolved_at: string | null;
    reported_by: PersonRef;
}

/** Values of the incident drawer (C-IncidentForm). Dates and times are Bogotá wall time. */
export interface IncidentFormData {
    title: string;
    /** YYYY-MM-DD. */
    started_on: string;
    /** HH:mm (24 h). */
    started_time: string;
    impact: IncidentImpact | null;
    /** "Qué pasa". */
    description: string;
    status: 'open' | 'resolved';
    /** Required only when `status` is resolved ("Terminó · fecha y hora"). */
    ended_on: string;
    ended_time: string;
    /** "Causa y solución", required only when resolved. */
    resolution: string;
    /** People to notify by mail and Telegram. */
    notify_ids: number[];
    /** Also notify the product owner. */
    notify_owner: boolean;
}

/**
 * Body of `ai.products.incidents.store`. Error keys: `title`, `started_at`, `impact`, `description`, `ended_at`,
 * `resolution`, `notify_ids`. The server records `origin: 'manual'` and the reporter (the signed-in person).
 */
export interface IncidentStorePayload {
    title: string;
    /** Bogotá wall time `YYYY-MM-DDTHH:mm`. */
    started_at: string;
    impact: IncidentImpact | null;
    description: string;
    status: 'open' | 'resolved';
    /** Null while open. */
    ended_at: string | null;
    resolution: string | null;
    notify_ids: number[];
    notify_owner: boolean;
}

export interface IncidentFormSetup {
    /** True at `ai.products.incidents.create` or after a failed store: the drawer starts open. */
    open: boolean;
    /** Initial values: today, the current time, the IT people checked. The old input after a failed store. */
    defaults: IncidentFormData;
    /** IT people who can be notified ("Jhon Castro", "Leonardo Herrera"). */
    notify_people: PersonRef[];
    /** Product owner shown next to "Dueño" ("[DUEÑO]" while unknown); null hides the option. */
    owner_label: string | null;
    /** The person registering it ("Lo registra Jhon Castro"). */
    reporter: PersonRef;
}

export interface ProductsShowProps {
    /** `monthly_cost` is null without `ai.costs.view`. */
    product: ProductDetail;
    health: ProductHealth;
    /** Newest first; empty while nobody registered one. */
    incidents: ProductIncident[];
    /** "4 h a la semana × 5 personas de Cartera"; null when there is nothing to explain. */
    hours_note: string | null;
    /** "Vercel, base de datos y dominio"; null without `ai.costs.view`. */
    cost_note: string | null;
    /** Second line of each open request, by key: "Proyecto P-07 · entrega proyectada 14 nov". */
    request_notes: Record<string, string>;
    /** The "Registrar incidente" drawer. */
    incident_form: IncidentFormSetup;
    /** Laravel validation errors from `ai.products.incidents.store`. */
    errors?: FormErrors;
    now: string;
}

// ---- Product form -------------------------------------------------------------

/**
 * Body of `ai.products.store` / `ai.products.update` and the form state (C-ProductForm).
 * Error keys are the field names (`name`, `slug`, `company_ids`, `area_id`, `version`, `url`, …).
 */
export interface ProductFormData {
    name: string;
    /** Lowercase letters, digits and hyphens; unique. Filled from the name on create until edited by hand. */
    slug: string;
    /** One company, or both ("Segurex y Derivados"). */
    company_ids: number[];
    /** Main area. */
    area_id: number | null;
    /** Null while unknown (shown as "[DUEÑO]"). */
    owner_id: number | null;
    description: string;
    /** Requests linked to the product ("Solicitudes vinculadas"). */
    request_keys: string[];
    /** "v2.4.0" (format v1.2.3). */
    version: string;
    state: ProductState;
    kind: ProductKind;
    /** "Laravel", "Next.js". */
    stack: string;
    /** One of `ProductFormOptions.hosts`. */
    host: string;
    /** Empty string = not published yet. */
    url: string;
}

/** A request that can be linked: open requests without a product, plus the ones already linked. */
export type LinkableRequest = Pick<RequestSummary, 'key' | 'title' | 'status'>;

export interface ProductFormOptions {
    areas: AreaOption[];
    /** People who can own a product ("Paola Ríos · Coordinadora de ventas"). */
    owners: PersonRef[];
    /** "Dónde corre": GoDaddy, Vercel, Equipo local, Servidor de SAP. */
    hosts: string[];
    /** Searched client-side by key or title. */
    requests: LinkableRequest[];
    /** Laravel validation errors from store/update. */
    errors?: FormErrors;
}

export interface ProductsCreateProps extends ProductFormOptions {
    /** Blank values (company = the selected one, state "En desarrollo", version "v0.1.0"); the old input after errors. */
    form: ProductFormData;
}

export interface ProductsEditProps extends ProductFormOptions {
    product: Pick<Product, 'slug' | 'name' | 'company_id'>;
    form: ProductFormData;
}

// ---- Docs -------------------------------------------------------------------

/** Tree and list row: a summary plus who touched it last. */
export interface DocListItem extends DocSummary {
    updated_by: PersonRef;
}

export interface DocsIndexProps {
    /** Every doc the viewer can see, grouped client-side by owner (server order is kept). */
    docs: DocListItem[];
    now: string;
}

export interface DocsEditProps {
    doc: Doc;
    /** Same list as Docs/Index, for the left tree. */
    tree: DocListItem[];
    /** Last autosave instant; updated by the autosave partial reload. */
    saved_at: string;
    /** Published version number; updated by the autosave partial reload. */
    version: number;
    /** Company of the doc's project or product ("Quién lo puede ver: Todo Segurex"). */
    company_id: number;
    now: string;
}

/** Where a new doc can go: projects and products the viewer works on. */
export interface DocOwnerOption {
    kind: 'project' | 'product';
    key: string;
    name: string;
}

export interface DocStorePayload {
    template: DocTemplate;
    owner_kind: 'project' | 'product';
    owner_key: string;
    title: string;
}

export interface DocsCreateProps {
    owners: DocOwnerOption[];
    /** Preselected from `?owner_kind=&owner_key=`; null = first owner. */
    defaults: { owner_kind: 'project' | 'product'; owner_key: string } | null;
}

export interface DocCompare {
    /** Version on the left of the list (selected). */
    version: number;
    /** Version it is compared with: the current one, or the previous when the current is selected. */
    against: number;
    lines: DiffLine[];
    /** Full Markdown of the selected version ("Versión completa"). */
    markdown: string;
}

export interface DocsHistoryProps {
    doc: DocSummary;
    /** Newest first. */
    versions: DocVersion[];
    /** Null only when the doc has a single version. */
    compare: DocCompare | null;
    now: string;
}

export interface RepoFile {
    /** "docs/arquitectura.md". */
    path: string;
    kind: 'file' | 'dir';
    /** Nesting level for the indent (0 = root). */
    depth: number;
    /** Last commit touching it; null for folders. */
    updated_at: string | null;
}

export type RepoState =
    | { connected: false }
    | {
          connected: true;
          /** True while the reading is a design sample (Stage B); shows the "Se conecta en la etapa D" banner. */
          sample: boolean;
          /** "[REPOSITORIO]" placeholder until the GitHub organization is known. */
          name: string;
          branch: string;
          fetched_at: string;
          files: RepoFile[];
          selected: {
              path: string;
              /** Raw Markdown as stored in GitHub. Rendered with MarkdownPreview, which escapes raw HTML. */
              markdown: string;
              updated_at: string;
              /** "versión 3.1.0"; null when no release touched it. */
              release: string | null;
              github_url: string;
          } | null;
      };

export interface DocsRepoProps {
    products: Pick<Product, 'slug' | 'name'>[];
    product_slug: string;
    repo: RepoState;
    now: string;
}

// ---- Search -----------------------------------------------------------------

/** Text with the server's matched ranges (`[start, end)`, UTF-16 offsets). */
export interface Highlighted {
    text: string;
    highlights: HighlightRange[];
}

export interface DocHit {
    doc: DocSummary;
    /** "… ## Ver la cartera por asesor. Abre Filtros…". */
    snippet: Highlighted;
}

export interface RequestHit {
    request: Pick<RequestSummary, 'key' | 'status' | 'company_id' | 'area'>;
    title: Highlighted;
}

export interface ProductHit {
    product: Pick<Product, 'slug' | 'name' | 'company_id' | 'state' | 'version'>;
    name: Highlighted;
    /** "v3.1.0 · Cartera y Ventas · filtro por asesor llega en la v3.2.0". */
    snippet: Highlighted;
}

export interface SearchQuery {
    q: string;
    types: SearchType[];
    company_id: number | null;
    area_id: number | null;
}

export interface SearchIndexProps {
    query: SearchQuery;
    /** Null before the first search (empty `q`). */
    results: {
        docs: DocHit[];
        requests: RequestHit[];
        products: ProductHit[];
    } | null;
    /** Counts per type for the filter, ignoring the type filter. */
    type_counts: Record<SearchType, number>;
    /** Results per company id, ignoring the company filter (only with `companies.view_all`). */
    company_counts: Record<number, number>;
    areas: AreaOption[];
    took_ms: number;
    now: string;
}
