/**
 * Gallery catalog built from `import.meta.glob` results, with no manual
 * registry: page files are keyed by Inertia component name
 * (`resources/js/Pages/Admin/Companies/Index.tsx` → `Admin/Companies/Index`)
 * and every `resources/js/mocks/*.ts` (except `shared.ts`) contributes its
 * `variants`. Mismatches become warnings instead of crashes.
 */

export const DESIGN_SYSTEM_PAGE = 'design-system';
/** Email templates from `resources/emails/`, previewed by the shell (not the frame). */
export const EMAILS_PAGE = 'emails';

export interface CatalogVariant {
    /** Unique across mock files: `{mockFile}:{key}`. */
    id: string;
    key: string;
    source: string;
    page: string;
    label: string;
    props: unknown;
}

export interface CatalogPage {
    name: string;
    /** Short name shown inside its group. */
    title: string;
    hasFile: boolean;
    variants: CatalogVariant[];
}

export interface CatalogGroup {
    key: string;
    label: string;
    pages: CatalogPage[];
}

export type CatalogWarning =
    | { kind: 'invalid-variant'; variant: string }
    | { kind: 'variant-without-page'; page: string; variant: string }
    | { kind: 'page-without-variants'; page: string };

export interface Catalog {
    groups: CatalogGroup[];
    warnings: CatalogWarning[];
}

const MODULES: { key: string; label: string }[] = [
    { key: DESIGN_SYSTEM_PAGE, label: 'Sistema de diseño' },
    { key: EMAILS_PAGE, label: 'Correos' },
    { key: 'auth', label: 'Autenticación' },
    { key: 'home', label: 'Inicio' },
    { key: 'employees', label: 'Colaboradores' },
    { key: 'profile', label: 'Perfil' },
    { key: 'admin', label: 'Administración' },
    { key: 'errors', label: 'Errores' },
];

const DESIGN_SYSTEM_VIEWS: { key: string; label: string }[] = [
    { key: 'components', label: 'Componentes' },
    { key: 'app', label: 'Layout de la app' },
    { key: 'admin', label: 'Layout de administración' },
    { key: 'guest', label: 'Layout de invitado' },
];

// ---- paths ----------------------------------------------------------------

export function pageNameFromPath(path: string): string | null {
    const match = /\/Pages\/(.+)\.tsx$/.exec(path.replace(/\\/g, '/'));
    if (match?.[1] === undefined || /\.(test|spec)$/.test(match[1])) return null;
    return match[1];
}

export function mockNameFromPath(path: string): string | null {
    const match = /\/mocks\/([^/]+)\.ts$/.exec(path.replace(/\\/g, '/'));
    const name = match?.[1];
    if (name === undefined || name === 'shared' || /\.(test|spec|d)$/.test(name)) return null;
    return name;
}

function moduleKeyOf(page: string): string {
    if (page === DESIGN_SYSTEM_PAGE || page === EMAILS_PAGE) return page;
    return (page.split('/')[0] ?? page).toLowerCase();
}

export function typesFileFor(page: string): string | null {
    if (page === DESIGN_SYSTEM_PAGE || page === EMAILS_PAGE) return null;
    return `resources/js/types/pages/${moduleKeyOf(page)}.ts`;
}

function titleOf(page: string): string {
    if (page === DESIGN_SYSTEM_PAGE) return 'Componentes y layouts';
    if (page === EMAILS_PAGE) return 'Plantillas de correo';
    const parts = page.split('/');
    const rest = parts.slice(1).join('/');
    // A bare "Index" says nothing on its own; keep the module in that case.
    return parts.length > 1 && rest !== 'Index' ? rest : page;
}

// ---- build ----------------------------------------------------------------

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

export function buildCatalog(
    pagePaths: readonly string[],
    mockModules: Record<string, unknown>,
    emailTemplates: readonly { key: string; label: string }[] = [],
): Catalog {
    const files = new Set(pagePaths.map(pageNameFromPath).filter((name): name is string => name !== null));
    const pages = new Map<string, CatalogPage>();
    const invalid: CatalogWarning[] = [];
    const orphanVariants: CatalogWarning[] = [];

    const pageFor = (name: string): CatalogPage => {
        let page = pages.get(name);
        if (page === undefined) {
            page = { name, title: titleOf(name), hasFile: name === DESIGN_SYSTEM_PAGE || name === EMAILS_PAGE || files.has(name), variants: [] };
            pages.set(name, page);
        }
        return page;
    };

    const design = pageFor(DESIGN_SYSTEM_PAGE);
    for (const view of DESIGN_SYSTEM_VIEWS) {
        design.variants.push({
            id: `${DESIGN_SYSTEM_PAGE}:${view.key}`,
            key: view.key,
            source: DESIGN_SYSTEM_PAGE,
            page: DESIGN_SYSTEM_PAGE,
            label: view.label,
            props: {},
        });
    }

    if (emailTemplates.length > 0) {
        const emails = pageFor(EMAILS_PAGE);
        for (const template of emailTemplates) {
            emails.variants.push({
                id: `${EMAILS_PAGE}:${template.key}`,
                key: template.key,
                source: EMAILS_PAGE,
                page: EMAILS_PAGE,
                label: template.label,
                props: {},
            });
        }
    }

    for (const [path, module] of Object.entries(mockModules)) {
        const source = mockNameFromPath(path);
        if (source === null || !isRecord(module) || !isRecord(module.variants)) continue;
        for (const [key, entry] of Object.entries(module.variants)) {
            const id = `${source}:${key}`;
            if (!isRecord(entry) || typeof entry.page !== 'string' || typeof entry.label !== 'string' || !isRecord(entry.props)) {
                invalid.push({ kind: 'invalid-variant', variant: id });
                continue;
            }
            const page = pageFor(entry.page);
            page.variants.push({ id, key, source, page: entry.page, label: entry.label, props: entry.props });
            if (!page.hasFile) orphanVariants.push({ kind: 'variant-without-page', page: entry.page, variant: id });
        }
    }

    const emptyPages: CatalogWarning[] = [];
    for (const name of [...files].sort((a, b) => a.localeCompare(b))) {
        if (!pages.has(name)) {
            pageFor(name);
            emptyPages.push({ kind: 'page-without-variants', page: name });
        }
    }

    const groups = new Map<string, CatalogGroup>();
    for (const module of MODULES) groups.set(module.key, { key: module.key, label: module.label, pages: [] });
    for (const page of pages.values()) {
        const key = moduleKeyOf(page.name);
        let group = groups.get(key);
        if (group === undefined) {
            group = { key, label: page.name.split('/')[0] ?? key, pages: [] };
            groups.set(key, group);
        }
        group.pages.push(page);
    }

    return {
        groups: [...groups.values()].filter((group) => group.pages.length > 0),
        warnings: [...invalid, ...orphanVariants, ...emptyPages],
    };
}

// ---- query ----------------------------------------------------------------

function normalize(text: string): string {
    return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
}

export function filterCatalog(catalog: Catalog, query: string): Catalog {
    const tokens = normalize(query).split(/\s+/).filter(Boolean);
    if (tokens.length === 0) return catalog;
    const matches = (haystack: string) => {
        const text = normalize(haystack);
        return tokens.every((token) => text.includes(token));
    };

    const groups = catalog.groups
        .map((group) => ({
            ...group,
            pages: group.pages
                .map((page) => ({
                    ...page,
                    variants: page.variants.filter((variant) =>
                        matches(`${group.label} ${page.name} ${page.title} ${variant.label} ${variant.key}`),
                    ),
                }))
                .filter((page, index) => {
                    const original = group.pages[index];
                    if (page.variants.length > 0) return true;
                    return original !== undefined && original.variants.length === 0 && matches(`${group.label} ${page.name}`);
                }),
        }))
        .filter((group) => group.pages.length > 0);

    return { groups, warnings: catalog.warnings };
}

export function flattenVariantIds(catalog: Catalog): string[] {
    return catalog.groups.flatMap((group) => group.pages.flatMap((page) => page.variants.map((variant) => variant.id)));
}

export function findVariant(catalog: Catalog, id: string): CatalogVariant | undefined {
    for (const group of catalog.groups) {
        for (const page of group.pages) {
            const variant = page.variants.find((candidate) => candidate.id === id);
            if (variant !== undefined) return variant;
        }
    }
    return undefined;
}

export function findPage(catalog: Catalog, name: string): CatalogPage | undefined {
    return catalog.groups.flatMap((group) => group.pages).find((page) => page.name === name);
}

/** Next visible variant for ↑/↓, clamped to the ends of the list. */
export function stepVariant(ids: readonly string[], current: string | null, delta: 1 | -1): string | null {
    if (ids.length === 0) return null;
    const index = current === null ? -1 : ids.indexOf(current);
    if (index === -1) return delta === 1 ? (ids[0] ?? null) : (ids[ids.length - 1] ?? null);
    return ids[Math.min(ids.length - 1, Math.max(0, index + delta))] ?? null;
}

export interface Selection {
    page: string;
    variant: CatalogVariant | null;
    /** The hash asked for a variant id that no mock defines. */
    missingVariant?: string;
}

export function resolveSelection(catalog: Catalog, wanted: { page: string | null; variant: string | null }): Selection {
    if (wanted.variant !== null) {
        const variant = findVariant(catalog, wanted.variant);
        if (variant !== undefined) return { page: variant.page, variant };
        return { page: wanted.page ?? DESIGN_SYSTEM_PAGE, variant: null, missingVariant: wanted.variant };
    }
    if (wanted.page !== null) {
        const page = findPage(catalog, wanted.page);
        return { page: wanted.page, variant: page?.variants[0] ?? null };
    }
    const first = catalog.groups[0]?.pages[0];
    return { page: first?.name ?? DESIGN_SYSTEM_PAGE, variant: first?.variants[0] ?? null };
}
