import { describe, expect, it } from 'vitest';
import { DEFAULT_STATE, fitScale, frameHash, parseHash, serializeHash, VIEWPORTS } from './hashState';

describe('parseHash', () => {
    it('returns the defaults for an empty hash', () => {
        expect(parseHash('')).toEqual(DEFAULT_STATE);
        expect(parseHash('#')).toEqual(DEFAULT_STATE);
    });

    it('reads every key, with or without the leading #', () => {
        const state = parseHash('#page=Admin/Companies/Index&variant=admin:companies-edit&vp=phone&theme=dark&brand=derivados&props=1');
        expect(state).toEqual({
            page: 'Admin/Companies/Index',
            variant: 'admin:companies-edit',
            vp: 'phone',
            theme: 'dark',
            brand: 'derivados',
            props: true,
        });
        expect(parseHash('page=Home').page).toBe('Home');
    });

    it('decodes percent-encoded values', () => {
        expect(parseHash('#page=Admin%2FRoles%2FIndex').page).toBe('Admin/Roles/Index');
    });

    it('falls back to defaults for unknown enum values', () => {
        const state = parseHash('#vp=watch&theme=sepia&brand=acme&props=yes');
        expect(state.vp).toBe(DEFAULT_STATE.vp);
        expect(state.theme).toBe(DEFAULT_STATE.theme);
        expect(state.brand).toBe(DEFAULT_STATE.brand);
        expect(state.props).toBe(false);
    });
});

describe('serializeHash', () => {
    it('round-trips through parseHash', () => {
        const state = {
            page: 'Auth/Login',
            variant: 'auth:login-default',
            vp: 'tablet',
            theme: 'light',
            brand: 'andina',
            props: true,
        } as const;
        expect(parseHash(serializeHash(state))).toEqual(state);
    });

    it('keeps slashes and colons readable and omits empty selections', () => {
        const hash = serializeHash({ ...DEFAULT_STATE, page: 'Admin/Roles/Index', variant: 'admin:roles' });
        expect(hash).toBe('page=Admin/Roles/Index&variant=admin:roles&vp=desktop&theme=system&brand=example');
    });

    it('encodes characters that would break the hash', () => {
        const hash = serializeHash({ ...DEFAULT_STATE, variant: 'a&b=c d' });
        expect(parseHash(hash).variant).toBe('a&b=c d');
    });
});

describe('frameHash', () => {
    it('carries only what the frame needs', () => {
        const hash = frameHash({ ...DEFAULT_STATE, page: 'Home', variant: 'home:requester', vp: 'phone', props: true, theme: 'dark' });
        expect(hash).toBe('page=Home&variant=home:requester&theme=dark&brand=example');
    });
});

describe('fitScale', () => {
    it('never scales up', () => {
        expect(fitScale({ width: 390, height: 844 }, { width: 2000, height: 2000 })).toBe(1);
    });

    it('scales down to the tightest dimension', () => {
        expect(fitScale({ width: 1440, height: 900 }, { width: 720, height: 900 })).toBe(0.5);
        expect(fitScale({ width: 390, height: 844 }, { width: 1000, height: 422 })).toBe(0.5);
    });

    it('guards against an unmeasured area', () => {
        expect(fitScale({ width: 1440, height: 900 }, { width: 0, height: 0 })).toBe(1);
    });
});

describe('VIEWPORTS', () => {
    it('defines the agreed device sizes', () => {
        expect(VIEWPORTS.phone.size).toEqual({ width: 390, height: 844 });
        expect(VIEWPORTS.tablet.size).toEqual({ width: 834, height: 1194 });
        expect(VIEWPORTS.desktop.size).toEqual({ width: 1440, height: 900 });
        expect(VIEWPORTS.fit.size).toBeNull();
    });
});
