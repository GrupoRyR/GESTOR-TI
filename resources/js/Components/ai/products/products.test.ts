import { describe, expect, it } from 'vitest';
import { joinWords, openRequestsText, productTotals, ratingText, requestsBreakdown } from './products';

const product = (rating: { average: number; count: number } | null, hours: number | null, cost: number | null, open: number) => ({
    rating,
    hours_saved_per_month: hours,
    monthly_cost: cost,
    open_requests: open,
});

describe('productTotals', () => {
    it('adds hours, cost and open requests and weighs the rating by votes', () => {
        const totals = productTotals([
            product({ average: 4.2, count: 18 }, 64, 380_000, 3),
            product({ average: 4.0, count: 9 }, 38, 210_000, 3),
            product({ average: 3.8, count: 6 }, 22, 90_000, 0),
            product({ average: 4.6, count: 12 }, 86, 120_000, 2),
            product(null, null, 0, 0),
        ]);
        expect(totals.rating).toBeCloseTo(4.213, 3);
        expect({ ...totals, rating: null }).toEqual({ count: 5, rating: null, ratings: 45, hours: 210, cost: 800_000, open: 8 });
    });

    it('hides the cost when the viewer cannot see it', () => {
        expect(productTotals([product(null, 10, null, 0)]).cost).toBeNull();
    });

    it('has no rating without votes', () => {
        expect(productTotals([]).rating).toBeNull();
    });
});

describe('product wording', () => {
    it('joins area names in Spanish', () => {
        expect(joinWords(['Cartera'])).toBe('Cartera');
        expect(joinWords(['Cartera', 'Ventas'])).toBe('Cartera y Ventas');
        expect(joinWords(['A', 'B', 'C'])).toBe('A, B y C');
        expect(joinWords([])).toBe('');
    });

    it('writes ratings with a comma', () => {
        expect(ratingText(4.6)).toBe('4,6 / 5');
        expect(ratingText(4)).toBe('4,0 / 5');
    });

    it('counts open requests', () => {
        expect(openRequestsText(0)).toBe('Ninguna');
        expect(openRequestsText(1)).toBe('1 abierta');
        expect(openRequestsText(3)).toBe('3 abiertas');
    });

    it('summarises open requests by status', () => {
        expect(requestsBreakdown([{ status: 'in_development' }, { status: 'received' }, { status: 'received' }])).toBe('1 en desarrollo · 2 recibidas');
        expect(requestsBreakdown([{ status: 'in_development' }, { status: 'received' }])).toBe('1 en desarrollo · 1 recibida');
        expect(requestsBreakdown([])).toBe('Ninguna abierta');
    });
});
