import { describe, expect, it } from 'vitest';
import {
    adminConsentUrl,
    EMPTY_WIZARD,
    firstStepWithErrors,
    parseDomains,
    slugify,
    validateStep,
    type WizardData,
} from './companyWizard';

const TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

const READY: WizardData = {
    ...EMPTY_WIZARD,
    name: 'Andina Pruebas S.A.S.',
    short_name: 'Andina',
    slug: 'andina',
    domains: 'andinapruebas.com',
    tenant_id: TENANT,
    primary: '#0F8A7E',
    accent: '#3FBFAE',
    dark: '#0A4F48',
    sender_email: 'andina.info@andinapruebas.com',
};

describe('slugify', () => {
    it('lowercases, strips accents and joins words with dashes', () => {
        expect(slugify('Soluciones y Derivados')).toBe('soluciones-y-derivados');
        expect(slugify('  Compañía Ñandú S.A.S. ')).toBe('compania-nandu-s-a-s');
    });
});

describe('parseDomains', () => {
    it('splits on commas, spaces and new lines, drops "@" and duplicates', () => {
        expect(parseDomains('segurex.com, @Segurex.com\nsegurex.co')).toEqual(['segurex.com', 'segurex.co']);
        expect(parseDomains('  ')).toEqual([]);
    });
});

describe('validateStep', () => {
    it('step 1 requires name, short name, a valid slug and at least one domain', () => {
        expect(validateStep(1, EMPTY_WIZARD)).toEqual({
            name: 'Escribe el nombre de la empresa.',
            short_name: 'Escribe un nombre corto.',
            slug: 'Escribe el identificador.',
            domains: 'Escribe al menos un dominio de correo.',
        });
        expect(validateStep(1, { ...READY, slug: 'Andina Pruebas' }).slug).toBe('Usa solo minúsculas, números y guiones.');
        expect(validateStep(1, { ...READY, domains: 'andina' }).domains).toBe('«andina» no parece un dominio.');
        expect(validateStep(1, READY)).toEqual({});
    });

    it('step 2 requires a tenant ID in GUID form', () => {
        expect(validateStep(2, { ...READY, tenant_id: '' }).tenant_id).toBe('Pega el ID de inquilino de Microsoft.');
        expect(validateStep(2, { ...READY, tenant_id: 'andina.onmicrosoft.com' }).tenant_id).toBe(
            'El ID tiene la forma 00000000-0000-0000-0000-000000000000.',
        );
        expect(validateStep(2, READY)).toEqual({});
    });

    it('step 3 requires the light logo and three full hex colors', () => {
        const errors = validateStep(3, { ...READY, primary: '#0F8A7' });
        expect(errors).toEqual({ logo: 'Sube el logo para fondo claro.', 'colors.primary': 'Usa un color como #0F8A7E.' });
        expect(validateStep(3, { ...READY, has_logo: true })).toEqual({});
    });

    it('step 4 requires a sender email on one of the company domains', () => {
        expect(validateStep(4, { ...READY, sender_email: '' }).sender_email).toBe('Escribe el correo emisor.');
        expect(validateStep(4, { ...READY, sender_email: 'no-es-correo' }).sender_email).toBe('Ese correo no es válido.');
        expect(validateStep(4, { ...READY, sender_email: 'info@gmail.com' }).sender_email).toBe(
            'Debe ser de uno de sus dominios: andinapruebas.com.',
        );
        expect(validateStep(4, READY)).toEqual({});
    });

    it('step 5 re-checks everything before sending', () => {
        expect(Object.keys(validateStep(5, { ...READY, tenant_id: '' }))).toEqual(['tenant_id', 'logo']);
        expect(validateStep(5, { ...READY, has_logo: true })).toEqual({});
    });
});

describe('firstStepWithErrors', () => {
    it('returns the earliest step that owns a server error', () => {
        expect(firstStepWithErrors({ sender_email: 'x', tenant_id: 'y' })).toBe(2);
        expect(firstStepWithErrors({ 'colors.accent': 'x' })).toBe(3);
        expect(firstStepWithErrors({ logo_dark: 'x' })).toBe(3);
        expect(firstStepWithErrors({ slug: 'x' })).toBe(1);
    });

    it('falls back to the review step for unknown fields and null without errors', () => {
        expect(firstStepWithErrors({ active: 'x' })).toBe(5);
        expect(firstStepWithErrors({})).toBeNull();
    });
});

describe('adminConsentUrl', () => {
    const TEMPLATE = 'https://login.microsoftonline.com/{tenant}/adminconsent?client_id=abc&redirect_uri=https%3A%2F%2Fgestor';

    it('puts the tenant into the template', () => {
        expect(adminConsentUrl(TEMPLATE, TENANT)).toBe(
            `https://login.microsoftonline.com/${TENANT}/adminconsent?client_id=abc&redirect_uri=https%3A%2F%2Fgestor`,
        );
    });

    it('is null until the tenant is a GUID, so the link cannot open a broken page', () => {
        expect(adminConsentUrl(TEMPLATE, '')).toBeNull();
        expect(adminConsentUrl(TEMPLATE, 'andina/../x')).toBeNull();
    });

    it('trims and lowercases the tenant', () => {
        expect(adminConsentUrl(TEMPLATE, `  ${TENANT.toUpperCase()} `)).toContain(`/${TENANT}/`);
    });
});
