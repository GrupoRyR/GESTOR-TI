import { describe, expect, it } from 'vitest';
import {
    buildCatalog,
    DESIGN_SYSTEM_PAGE,
    filterCatalog,
    findVariant,
    flattenVariantIds,
    mockNameFromPath,
    pageNameFromPath,
    resolveSelection,
    stepVariant,
    typesFileFor,
} from './catalog';

describe('pageNameFromPath', () => {
    it('maps a glob path to the Inertia component name', () => {
        expect(pageNameFromPath('../resources/js/Pages/Admin/Companies/Index.tsx')).toBe('Admin/Companies/Index');
        expect(pageNameFromPath('/resources/js/Pages/Home.tsx')).toBe('Home');
    });

    it('ignores files outside Pages and test files', () => {
        expect(pageNameFromPath('/resources/js/Layouts/AppLayout.tsx')).toBeNull();
        expect(pageNameFromPath('/resources/js/Pages/Home.test.tsx')).toBeNull();
    });
});

describe('mockNameFromPath', () => {
    it('returns the mock file name and skips shared and tests', () => {
        expect(mockNameFromPath('../resources/js/mocks/admin.ts')).toBe('admin');
        expect(mockNameFromPath('../resources/js/mocks/shared.ts')).toBeNull();
        expect(mockNameFromPath('../resources/js/mocks/admin.test.ts')).toBeNull();
    });
});

describe('typesFileFor', () => {
    it('points at the module types file', () => {
        expect(typesFileFor('Admin/Companies/Index')).toBe('resources/js/types/pages/admin.ts');
        expect(typesFileFor('Home')).toBe('resources/js/types/pages/home.ts');
        expect(typesFileFor(DESIGN_SYSTEM_PAGE)).toBeNull();
    });
});

const PAGE_PATHS = [
    '../resources/js/Pages/Admin/Roles/Index.tsx',
    '../resources/js/Pages/Auth/Emergency.tsx',
    '../resources/js/Pages/Auth/Login.tsx',
    '../resources/js/Pages/Home.tsx',
    '../resources/js/Pages/Profile/Orphan.tsx',
];

const MOCKS = {
    '../resources/js/mocks/shared.ts': { variants: { nope: { page: 'Home', label: 'x', props: {} } } },
    '../resources/js/mocks/auth.ts': {
        variants: {
            'login-default': { page: 'Auth/Login', label: 'Entrar · normal', props: { a: 1 } },
            'emergency-default': { page: 'Auth/Emergency', label: 'Emergencia · formulario', props: {} },
            'login-error': { page: 'Auth/Login', label: 'Entrar · Microsoft no respondió', props: {} },
        },
    },
    '../resources/js/mocks/admin.ts': {
        variants: {
            roles: { page: 'Admin/Roles/Index', label: 'Roles y permisos', props: {} },
            ghost: { page: 'Admin/Ghost/Index', label: 'Página que no existe', props: {} },
        },
    },
    '../resources/js/mocks/home.ts': {
        variants: {
            requester: { page: 'Home', label: 'Solicitante · Andrés', props: {} },
            broken: { label: 'Sin página', props: {} },
        },
    },
};

const catalog = buildCatalog(PAGE_PATHS, MOCKS);

describe('buildCatalog', () => {
    it('orders groups by module, with the design system first', () => {
        expect(catalog.groups.map((group) => group.label)).toEqual([
            'Sistema de diseño',
            'Autenticación',
            'Inicio',
            'Perfil',
            'Administración',
        ]);
    });

    it('keeps the mock declaration order for pages and variants', () => {
        const auth = catalog.groups.find((group) => group.key === 'auth');
        expect(auth?.pages.map((page) => page.name)).toEqual(['Auth/Login', 'Auth/Emergency']);
        expect(auth?.pages[0]?.variants.map((variant) => variant.id)).toEqual(['auth:login-default', 'auth:login-error']);
    });

    it('lists the design system views as variants of the first entry', () => {
        const design = catalog.groups[0];
        expect(design?.pages[0]?.name).toBe(DESIGN_SYSTEM_PAGE);
        expect(design?.pages[0]?.variants.map((variant) => variant.key)).toEqual(['components', 'app', 'admin', 'guest']);
    });

    it('ignores shared.ts', () => {
        expect(findVariant(catalog, 'shared:nope')).toBeUndefined();
    });

    it('reports pages without variants, variants without a page file and malformed variants', () => {
        expect(catalog.warnings).toEqual([
            { kind: 'invalid-variant', variant: 'home:broken' },
            { kind: 'variant-without-page', page: 'Admin/Ghost/Index', variant: 'admin:ghost' },
            { kind: 'page-without-variants', page: 'Profile/Orphan' },
        ]);
        const ghost = catalog.groups.flatMap((group) => group.pages).find((page) => page.name === 'Admin/Ghost/Index');
        expect(ghost?.hasFile).toBe(false);
        const orphan = catalog.groups.flatMap((group) => group.pages).find((page) => page.name === 'Profile/Orphan');
        expect(orphan).toMatchObject({ hasFile: true, variants: [] });
    });
});

describe('filterCatalog', () => {
    it('matches page names and labels, ignoring case and accents', () => {
        const filtered = filterCatalog(catalog, 'microsoft respondio');
        expect(flattenVariantIds(filtered)).toEqual(['auth:login-error']);
    });

    it('requires every word to match', () => {
        expect(flattenVariantIds(filterCatalog(catalog, 'roles inicio'))).toEqual([]);
        expect(flattenVariantIds(filterCatalog(catalog, 'admin roles'))).toEqual(['admin:roles']);
    });

    it('matches the group label', () => {
        expect(flattenVariantIds(filterCatalog(catalog, 'autenticacion'))).toEqual([
            'auth:login-default',
            'auth:login-error',
            'auth:emergency-default',
        ]);
    });

    it('returns everything for a blank query', () => {
        expect(filterCatalog(catalog, '   ').groups).toEqual(catalog.groups);
    });

    it('keeps a page without variants when its name matches', () => {
        const filtered = filterCatalog(catalog, 'orphan');
        expect(filtered.groups.flatMap((group) => group.pages).map((page) => page.name)).toEqual(['Profile/Orphan']);
    });
});

describe('stepVariant', () => {
    const ids = ['a', 'b', 'c'];

    it('moves up and down, clamped to the ends', () => {
        expect(stepVariant(ids, 'b', 1)).toBe('c');
        expect(stepVariant(ids, 'c', 1)).toBe('c');
        expect(stepVariant(ids, 'a', -1)).toBe('a');
    });

    it('starts at the first or last entry when the current one is not visible', () => {
        expect(stepVariant(ids, 'z', 1)).toBe('a');
        expect(stepVariant(ids, null, -1)).toBe('c');
        expect(stepVariant([], 'a', 1)).toBeNull();
    });
});

describe('resolveSelection', () => {
    it('prefers the variant, then the first variant of the page, then the first entry', () => {
        expect(resolveSelection(catalog, { page: 'Auth/Login', variant: 'auth:login-error' })).toMatchObject({
            page: 'Auth/Login',
            variant: { id: 'auth:login-error' },
        });
        expect(resolveSelection(catalog, { page: 'Auth/Login', variant: null })).toMatchObject({
            page: 'Auth/Login',
            variant: { id: 'auth:login-default' },
        });
        expect(resolveSelection(catalog, { page: null, variant: null })).toMatchObject({
            page: DESIGN_SYSTEM_PAGE,
            variant: { id: 'design-system:components' },
        });
    });

    it('keeps a page without variants selected, with no variant', () => {
        expect(resolveSelection(catalog, { page: 'Profile/Orphan', variant: null })).toEqual({
            page: 'Profile/Orphan',
            variant: null,
        });
    });

    it('reports an unknown variant instead of guessing', () => {
        expect(resolveSelection(catalog, { page: 'Home', variant: 'home:missing' })).toEqual({
            page: 'Home',
            variant: null,
            missingVariant: 'home:missing',
        });
    });
});
