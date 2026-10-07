import { describe, expect, it } from 'vitest';
import type { CompanyColors } from '@/types/shared';
import { brandStyle, contrastRatio } from './brand';

const COMPANIES: Record<string, CompanyColors> = {
    segurex: { primary: '#FF671F', accent: '#FF8A4C', dark: '#2B2B2B' },
    derivados: { primary: '#183F94', accent: '#3C6BD1', dark: '#03256C' },
    andina: { primary: '#0F8A7E', accent: '#3FBFAE', dark: '#0A4F48' },
};

const HEX = /^#[0-9A-F]{6}$/;

describe('contrastRatio', () => {
    it('is 21 for black on white and 1 for identical colors', () => {
        expect(contrastRatio('#000000', '#FFFFFF')).toBeCloseTo(21, 1);
        expect(contrastRatio('#FF671F', '#FF671F')).toBeCloseTo(1, 5);
    });

    it('is symmetric', () => {
        expect(contrastRatio('#183F94', '#FFFFFF')).toBeCloseTo(contrastRatio('#FFFFFF', '#183F94'), 5);
    });
});

describe('brandStyle', () => {
    for (const [name, colors] of Object.entries(COMPANIES)) {
        for (const scheme of ['light', 'dark'] as const) {
            describe(`${name} · ${scheme}`, () => {
                const style = brandStyle(colors, scheme);

                it('exposes every brand variable as an uppercase hex', () => {
                    for (const key of [
                        '--brand-primary',
                        '--brand-accent',
                        '--brand-dark',
                        '--brand-button',
                        '--brand-button-ink',
                        '--brand-tint',
                        '--brand-ink',
                    ]) {
                        expect(style[key], key).toMatch(HEX);
                    }
                });

                it('gives button text at least 4.5:1', () => {
                    expect(contrastRatio(style['--brand-button']!, style['--brand-button-ink']!)).toBeGreaterThanOrEqual(4.5);
                });

                it('gives ink on tint at least 7:1', () => {
                    expect(contrastRatio(style['--brand-ink']!, style['--brand-tint']!)).toBeGreaterThanOrEqual(7);
                });
            });
        }
    }

    it('uses white button ink in light and #121315 in dark', () => {
        expect(brandStyle(COMPANIES.segurex!, 'light')['--brand-button-ink']).toBe('#FFFFFF');
        expect(brandStyle(COMPANIES.segurex!, 'dark')['--brand-button-ink']).toBe('#121315');
    });

    it('keeps a primary that already passes as the light button', () => {
        expect(brandStyle(COMPANIES.derivados!, 'light')['--brand-button']).toBe('#183F94');
    });

    it('darkens Segurex orange for the light button but keeps its hue family', () => {
        const button = brandStyle(COMPANIES.segurex!, 'light')['--brand-button']!;
        expect(button).not.toBe('#FF671F');
        const r = parseInt(button.slice(1, 3), 16);
        const b = parseInt(button.slice(5, 7), 16);
        expect(r).toBeGreaterThan(b * 3);
    });

    it('produces a very light tint in light mode and a dark tint in dark mode', () => {
        expect(contrastRatio(brandStyle(COMPANIES.segurex!, 'light')['--brand-tint']!, '#FFFFFF')).toBeLessThan(1.15);
        expect(contrastRatio(brandStyle(COMPANIES.segurex!, 'dark')['--brand-tint']!, '#121315')).toBeLessThan(1.6);
    });
});
