/**
 * URL slugs for products ("toma-pedidos"): lowercase ASCII letters, digits
 * and single hyphens. The product form fills the slug from the name until
 * the person edits it by hand; the server still checks it is unique.
 */
export const SLUG_MAX_LENGTH = 60;

const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

/** "Cámaras de Diseño" → "camaras-de-diseno". */
export function slugify(text: string): string {
    const words = text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    return words.slice(0, SLUG_MAX_LENGTH).replace(/-+$/, '');
}

export function isValidSlug(slug: string): boolean {
    return slug.length <= SLUG_MAX_LENGTH && SLUG_PATTERN.test(slug);
}
