import { describe, expect, it } from 'vitest';
import { isValidSlug, slugify } from './slug';

describe('slugify', () => {
    it('turns a product name into lowercase words joined by hyphens', () => {
        expect(slugify('Toma Pedidos SAP')).toBe('toma-pedidos-sap');
    });

    it('drops accents and ñ marks', () => {
        expect(slugify('Cámaras de Diseño')).toBe('camaras-de-diseno');
    });

    it('collapses symbols and spaces and trims hyphens at the ends', () => {
        expect(slugify('  Portafolio · productos (v2)!  ')).toBe('portafolio-productos-v2');
        expect(slugify('a -- b')).toBe('a-b');
    });

    it('returns an empty string when nothing usable is left', () => {
        expect(slugify('  ·  ')).toBe('');
    });

    it('caps the length at 60 characters without a trailing hyphen', () => {
        const slug = slugify(`${'palabra '.repeat(12)}fin`);
        expect(slug.length).toBeLessThanOrEqual(60);
        expect(slug.endsWith('-')).toBe(false);
    });
});

describe('isValidSlug', () => {
    it('accepts lowercase letters, digits and single hyphens', () => {
        expect(isValidSlug('toma-pedidos')).toBe(true);
        expect(isValidSlug('robotcamara2')).toBe(true);
    });

    it('rejects uppercase, spaces, accents and edge or double hyphens', () => {
        for (const slug of ['Toma', 'toma pedidos', 'cámaras', '-toma', 'toma-', 'toma--pedidos', '']) {
            expect(isValidSlug(slug)).toBe(false);
        }
    });
});
