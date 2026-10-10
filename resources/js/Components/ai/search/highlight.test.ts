import { describe, expect, it } from 'vitest';
import { highlightSegments, parseSearchTypes, searchTypeParam } from './highlight';

describe('highlightSegments', () => {
    it('splits the text around the matched ranges', () => {
        expect(highlightSegments('Visualizador de cartera', [[16, 23]])).toEqual([
            { text: 'Visualizador de ', match: false },
            { text: 'cartera', match: true },
        ]);
    });

    it('returns the whole text unmatched without ranges', () => {
        expect(highlightSegments('Toma Pedidos', [])).toEqual([{ text: 'Toma Pedidos', match: false }]);
    });

    it('returns nothing for empty text', () => {
        expect(highlightSegments('', [[0, 3]])).toEqual([]);
    });

    it('sorts, clamps and merges overlapping or touching ranges', () => {
        expect(
            highlightSegments('cartera por asesor', [
                [12, 99],
                [0, 7],
                [5, 8],
                [8, 11],
            ]),
        ).toEqual([
            { text: 'cartera por', match: true },
            { text: ' ', match: false },
            { text: 'asesor', match: true },
        ]);
    });

    it('ignores empty, reversed and out-of-range ranges', () => {
        expect(
            highlightSegments('abc', [
                [2, 2],
                [3, 1],
                [-5, -1],
                [10, 12],
            ]),
        ).toEqual([{ text: 'abc', match: false }]);
    });

    it('clamps a range that starts before the text', () => {
        expect(highlightSegments('abc', [[-2, 1]])).toEqual([
            { text: 'a', match: true },
            { text: 'bc', match: false },
        ]);
    });
});

describe('search types', () => {
    it('omits the parameter when every type or none is selected', () => {
        expect(searchTypeParam(['docs', 'requests', 'products'])).toBeUndefined();
        expect(searchTypeParam([])).toBeUndefined();
    });

    it('joins a partial selection in a stable order', () => {
        expect(searchTypeParam(['products', 'docs'])).toBe('docs,products');
    });

    it('parses the parameter back, ignoring unknown values', () => {
        expect(parseSearchTypes('products,docs,otros')).toEqual(['docs', 'products']);
        expect(parseSearchTypes(null)).toEqual(['docs', 'requests', 'products']);
        expect(parseSearchTypes('')).toEqual(['docs', 'requests', 'products']);
    });
});
