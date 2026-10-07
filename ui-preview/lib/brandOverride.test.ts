import { describe, expect, it } from 'vitest';
import { applyBrandOverride, isGuestProps } from './brandOverride';

const SEGUREX = { id: 1, slug: 'segurex' };
const DERIVADOS = { id: 2, slug: 'derivados' };
const ANDINA = { id: 3, slug: 'andina' };
const ALL = [SEGUREX, DERIVADOS, ANDINA];

const appProps = { auth: { user: { id: 7 } }, companies: [SEGUREX, DERIVADOS], current_company_id: null, title: 'x' };
const guestProps = { auth: { user: null }, companies: [SEGUREX, DERIVADOS], error: null };

describe('isGuestProps', () => {
    it('treats props without current_company_id or without a user as guest', () => {
        expect(isGuestProps(appProps)).toBe(false);
        expect(isGuestProps(guestProps)).toBe(true);
        expect(isGuestProps({ status: 404 })).toBe(true);
        expect(isGuestProps({ ...appProps, auth: { user: null } })).toBe(true);
        expect(isGuestProps(null)).toBe(true);
    });
});

describe('applyBrandOverride', () => {
    it('leaves props untouched for "según el ejemplo"', () => {
        expect(applyBrandOverride(appProps, 'example', ALL)).toBe(appProps);
    });

    it('replaces current_company_id on app pages', () => {
        const result = applyBrandOverride(appProps, 'derivados', ALL);
        expect(result).toEqual({ ...appProps, current_company_id: 2 });
        expect(appProps.current_company_id).toBeNull();
    });

    it('adds the company to the list when the user cannot see it', () => {
        const result = applyBrandOverride(appProps, 'andina', ALL) as typeof appProps;
        expect(result.current_company_id).toBe(3);
        expect(result.companies.map((company) => company.id)).toEqual([1, 2, 3]);
    });

    it('ignores guest pages', () => {
        expect(applyBrandOverride(guestProps, 'derivados', ALL)).toBe(guestProps);
    });

    it('ignores an unknown brand', () => {
        expect(applyBrandOverride(appProps, 'acme', ALL)).toBe(appProps);
    });
});
