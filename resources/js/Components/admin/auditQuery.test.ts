import { describe, expect, it } from 'vitest';
import { auditQuery, isAuditFiltered } from './auditQuery';

describe('auditQuery', () => {
    it('always sends the range and omits empty filters', () => {
        expect(auditQuery({ causer_id: null, module: null, sensitive_only: false, range: '7d' })).toEqual({ range: '7d' });
    });

    it('sends every active filter, booleans as 1', () => {
        expect(auditQuery({ causer_id: 2, module: 'users', sensitive_only: true, range: 'all' })).toEqual({
            range: 'all',
            causer_id: 2,
            module: 'users',
            sensitive_only: 1,
        });
    });
});

describe('isAuditFiltered', () => {
    it('is false only for the default view (last 7 days, everything)', () => {
        expect(isAuditFiltered({ causer_id: null, module: null, sensitive_only: false, range: '7d' })).toBe(false);
        expect(isAuditFiltered({ causer_id: null, module: null, sensitive_only: false, range: '30d' })).toBe(true);
        expect(isAuditFiltered({ causer_id: null, module: null, sensitive_only: true, range: '7d' })).toBe(true);
    });
});
