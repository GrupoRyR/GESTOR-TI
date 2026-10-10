import { describe, expect, it } from 'vitest';
import { REVEAL_SECONDS, revealLeft, rotationState, rotationText, sessionLeft, startReveal, tickReveal, vaultAlerts } from './vault';

const T0 = Date.parse('2026-10-07T09:40:00-05:00');

describe('revealed value lifecycle', () => {
    const revealed = { item_id: 1, value: 'sk-muestra-1', expires_in_seconds: 30 };

    it('starts counting from the moment the value arrives', () => {
        const shown = startReveal(revealed, T0);
        expect(shown).toEqual({ itemId: 1, value: 'sk-muestra-1', hideAt: T0 + 30_000 });
        expect(revealLeft(shown, T0)).toBe(30);
        expect(revealLeft(shown, T0 + 6_200)).toBe(24);
    });

    it('never shows more than 30 seconds even if the server asks for more', () => {
        const shown = startReveal({ ...revealed, expires_in_seconds: 600 }, T0);
        expect(revealLeft(shown, T0)).toBe(REVEAL_SECONDS);
    });

    it('starts nothing without a value or with a non-positive duration', () => {
        expect(startReveal(null, T0)).toBeNull();
        expect(startReveal({ ...revealed, expires_in_seconds: 0 }, T0)).toBeNull();
        expect(startReveal({ ...revealed, value: '' }, T0)).toBeNull();
    });

    it('keeps the value while time is left and clears it at the deadline', () => {
        const shown = startReveal(revealed, T0);
        expect(tickReveal(shown, T0 + 29_000)).toBe(shown);
        expect(tickReveal(shown, T0 + 30_000)).toBeNull();
        expect(tickReveal(shown, T0 + 45_000)).toBeNull();
        expect(tickReveal(null, T0)).toBeNull();
        expect(revealLeft(null, T0)).toBe(0);
    });
});

describe('rotation state', () => {
    const base = { expires_at: null, expiry_note: null, rotation_days: 90 };

    it('warns within 30 days', () => {
        expect(rotationState({ ...base, expires_at: '2026-10-19' }, '2026-10-07')).toEqual({ level: 'soon', days: 12, date: '19 oct', sentence: 'Rotar en 12 días' });
    });

    it('marks expired items', () => {
        expect(rotationState({ ...base, expires_at: '2026-10-05' }, '2026-10-07')).toMatchObject({ level: 'expired', days: -2, sentence: 'Venció hace 2 días' });
    });

    it('says today and tomorrow', () => {
        expect(rotationState({ ...base, expires_at: '2026-10-07' }, '2026-10-07').sentence).toBe('Rotar hoy');
        expect(rotationState({ ...base, expires_at: '2026-10-08' }, '2026-10-07').sentence).toBe('Rotar mañana');
    });

    it('shows the year for dates in another year', () => {
        expect(rotationState({ ...base, expires_at: '2027-01-03' }, '2026-10-07')).toMatchObject({ level: 'ok', date: '3 ene 2027' });
    });

    it('uses the expiry note when the value is still missing', () => {
        expect(rotationState({ ...base, expiry_note: 'Falta recibirla' }, '2026-10-07')).toEqual({ level: 'pending', days: null, date: 'Falta recibirla', sentence: 'Falta recibirla' });
    });

    it('has no date without expiry', () => {
        expect(rotationState(base, '2026-10-07')).toEqual({ level: 'none', days: null, date: '—', sentence: 'Sin vencimiento' });
    });

    it('lists only expired or soon items, most urgent first', () => {
        const items = [
            { id: 1, ...base, expires_at: '2026-10-30' },
            { id: 2, ...base, expires_at: '2027-01-03' },
            { id: 3, ...base, expires_at: '2026-10-01' },
            { id: 4, ...base },
        ];
        expect(vaultAlerts(items, '2026-10-07').map((alert) => alert.item.id)).toEqual([3, 1]);
    });

    it('phrases the rotation period', () => {
        expect(rotationText(90)).toBe('Cada 90 días');
        expect(rotationText(365)).toBe('Cada año');
        expect(rotationText(1)).toBe('Cada día');
        expect(rotationText(null)).toBe('Sin rotación');
    });
});

describe('vault session', () => {
    it('counts down to the auto-lock and is zero when locked', () => {
        const open = { state: 'open' as const, confirmed_at: '2026-10-07T09:39:00-05:00', expires_at: '2026-10-07T09:49:12-05:00' };
        expect(sessionLeft(open, new Date(T0))).toBe(552);
        expect(sessionLeft({ state: 'locked' }, new Date(T0))).toBe(0);
    });
});
