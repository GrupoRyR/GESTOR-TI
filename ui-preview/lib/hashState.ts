/**
 * Gallery state as it lives in the URL hash, so a link reproduces the exact
 * view: `#page=…&variant=…&vp=…&theme=…&brand=…[&props=1]`.
 */

export type Viewport = 'phone' | 'tablet' | 'desktop' | 'fit';
export type ThemeChoice = 'system' | 'light' | 'dark';
export type BrandChoice = 'example' | 'segurex' | 'derivados' | 'andina';

export interface Size {
    width: number;
    height: number;
}

export interface GalleryState {
    page: string | null;
    variant: string | null;
    vp: Viewport;
    theme: ThemeChoice;
    brand: BrandChoice;
    /** Props side panel open. */
    props: boolean;
}

export const VIEWPORTS: Record<Viewport, { label: string; size: Size | null }> = {
    phone: { label: 'Celular', size: { width: 390, height: 844 } },
    tablet: { label: 'Tablet', size: { width: 834, height: 1194 } },
    desktop: { label: 'Escritorio', size: { width: 1440, height: 900 } },
    fit: { label: 'Ajustar', size: null },
};

export const THEMES: Record<ThemeChoice, string> = {
    system: 'Sistema',
    light: 'Claro',
    dark: 'Oscuro',
};

export const BRANDS: Record<BrandChoice, string> = {
    example: 'Según el ejemplo',
    segurex: 'Segurex',
    derivados: 'Derivados',
    andina: 'Andina',
};

export const DEFAULT_STATE: GalleryState = {
    page: null,
    variant: null,
    vp: 'desktop',
    theme: 'system',
    brand: 'example',
    props: false,
};

function oneOf<T extends string>(value: string | null, allowed: Record<T, unknown>, fallback: T): T {
    return value !== null && Object.hasOwn(allowed, value) ? (value as T) : fallback;
}

function nonEmpty(value: string | null): string | null {
    return value === null || value === '' ? null : value;
}

export function parseHash(hash: string): GalleryState {
    const params = new URLSearchParams(hash.startsWith('#') ? hash.slice(1) : hash);
    return {
        page: nonEmpty(params.get('page')),
        variant: nonEmpty(params.get('variant')),
        vp: oneOf(params.get('vp'), VIEWPORTS, DEFAULT_STATE.vp),
        theme: oneOf(params.get('theme'), THEMES, DEFAULT_STATE.theme),
        brand: oneOf(params.get('brand'), BRANDS, DEFAULT_STATE.brand),
        props: params.get('props') === '1',
    };
}

/** Percent-encode, but keep `/` and `:` readable since they are safe in a fragment. */
function encode(value: string): string {
    return encodeURIComponent(value).replace(/%2F/gi, '/').replace(/%3A/gi, ':');
}

function join(entries: [string, string | null][]): string {
    return entries
        .filter((entry): entry is [string, string] => entry[1] !== null)
        .map(([key, value]) => `${key}=${encode(value)}`)
        .join('&');
}

export function serializeHash(state: GalleryState): string {
    return join([
        ['page', state.page],
        ['variant', state.variant],
        ['vp', state.vp],
        ['theme', state.theme],
        ['brand', state.brand],
        ['props', state.props ? '1' : null],
    ]);
}

/** The subset the frame needs: viewport and panels belong to the shell. */
export function frameHash(state: GalleryState): string {
    return join([
        ['page', state.page],
        ['variant', state.variant],
        ['theme', state.theme],
        ['brand', state.brand],
    ]);
}

/** Scale that fits `frame` inside `area` without ever enlarging it. */
export function fitScale(frame: Size, area: Size): number {
    if (area.width <= 0 || area.height <= 0) return 1;
    return Math.min(1, area.width / frame.width, area.height / frame.height);
}
