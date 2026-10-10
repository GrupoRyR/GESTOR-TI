import { describe, expect, it } from 'vitest';
import { buttonContrast, contrastNote, formatRatio } from './brandContrast';

describe('formatRatio', () => {
    it('uses a decimal comma and one decimal', () => {
        expect(formatRatio(4.123)).toBe('4,1:1');
        expect(formatRatio(21)).toBe('21,0:1');
    });
});

describe('buttonContrast', () => {
    it('keeps the primary when white on it already passes AA', () => {
        const result = buttonContrast({ primary: '#183F94', accent: '#6D94E8', dark: '#03256C' });
        expect(result.passes).toBe(true);
        expect(result.button).toBe('#183F94');
    });

    it('darkens the button when white on the primary fails AA', () => {
        const result = buttonContrast({ primary: '#FF671F', accent: '#666666', dark: '#8F360B' });
        expect(result.passes).toBe(false);
        expect(result.ratio).toBeLessThan(4.5);
        expect(result.button).not.toBe('#FF671F');
        expect(result.buttonRatio).toBeGreaterThanOrEqual(4.5);
    });
});

describe('contrastNote', () => {
    it('names the button color when the primary fails', () => {
        const colors = { primary: '#FF671F', accent: '#666666', dark: '#8F360B' };
        expect(contrastNote(colors)).toBe(`Blanco sobre el principal no pasa AA; los botones usan ${buttonContrast(colors).button}.`);
    });

    it('confirms when the primary passes', () => {
        expect(contrastNote({ primary: '#183F94', accent: '#6D94E8', dark: '#03256C' })).toMatch(
            /^Blanco sobre el principal pasa AA \(\d+,\d:1\); los botones usan #183F94\.$/,
        );
    });
});
