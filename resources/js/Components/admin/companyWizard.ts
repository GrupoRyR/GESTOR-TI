/**
 * Pure logic of the "Agregar empresa" wizard: field values, per-step
 * validation (Spanish messages, same keys the server uses), mapping server
 * errors back to a step, and the Microsoft admin-consent link.
 */

export type WizardStep = 1 | 2 | 3 | 4 | 5;

export const WIZARD_STEPS: { step: WizardStep; label: string }[] = [
    { step: 1, label: 'Datos de la empresa' },
    { step: 2, label: 'Cuenta de Microsoft' },
    { step: 3, label: 'Marca' },
    { step: 4, label: 'Correo emisor' },
    { step: 5, label: 'Revisar y activar' },
];

export interface WizardData {
    name: string;
    short_name: string;
    slug: string;
    /** Free text as typed: "segurex.com, segurex.co". */
    domains: string;
    tenant_id: string;
    /** A light-background logo file was chosen. */
    has_logo: boolean;
    primary: string;
    accent: string;
    dark: string;
    sender_email: string;
}

export const EMPTY_WIZARD: WizardData = {
    name: '',
    short_name: '',
    slug: '',
    domains: '',
    tenant_id: '',
    has_logo: false,
    primary: '#17191C',
    accent: '#5E6168',
    dark: '#17191C',
    sender_email: '',
};

export type WizardErrors = Record<string, string>;

const GUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const DOMAIN = /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/;
const EMAIL = /^[^\s@]+@([^\s@]+)$/;
const HEX = /^#[0-9a-fA-F]{6}$/;

/** "Compañía Ñandú S.A.S." → "compania-nandu-s-a-s". */
export function slugify(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

/** Domains typed with commas, spaces or new lines; lowercased, no "@", unique. */
export function parseDomains(value: string): string[] {
    const items = value
        .split(/[\s,;]+/)
        .map((item) => item.trim().replace(/^@/, '').toLowerCase())
        .filter(Boolean);
    return [...new Set(items)];
}

function companyErrors(data: WizardData): WizardErrors {
    const errors: WizardErrors = {};
    if (!data.name.trim()) errors.name = 'Escribe el nombre de la empresa.';
    if (!data.short_name.trim()) errors.short_name = 'Escribe un nombre corto.';
    if (!data.slug.trim()) errors.slug = 'Escribe el identificador.';
    else if (!SLUG.test(data.slug)) errors.slug = 'Usa solo minúsculas, números y guiones.';
    const domains = parseDomains(data.domains);
    const invalid = domains.find((domain) => !DOMAIN.test(domain));
    if (domains.length === 0) errors.domains = 'Escribe al menos un dominio de correo.';
    else if (invalid) errors.domains = `«${invalid}» no parece un dominio.`;
    return errors;
}

function microsoftErrors(data: WizardData): WizardErrors {
    const tenant = data.tenant_id.trim();
    if (!tenant) return { tenant_id: 'Pega el ID de inquilino de Microsoft.' };
    if (!GUID.test(tenant)) return { tenant_id: 'El ID tiene la forma 00000000-0000-0000-0000-000000000000.' };
    return {};
}

function brandErrors(data: WizardData): WizardErrors {
    const errors: WizardErrors = {};
    if (!data.has_logo) errors.logo = 'Sube el logo para fondo claro.';
    for (const key of ['primary', 'accent', 'dark'] as const) {
        if (!HEX.test(data[key])) errors[`colors.${key}`] = 'Usa un color como #0F8A7E.';
    }
    return errors;
}

function senderErrors(data: WizardData): WizardErrors {
    const email = data.sender_email.trim().toLowerCase();
    if (!email) return { sender_email: 'Escribe el correo emisor.' };
    const match = EMAIL.exec(email);
    if (!match || !DOMAIN.test(match[1] ?? '')) return { sender_email: 'Ese correo no es válido.' };
    const domains = parseDomains(data.domains);
    if (domains.length > 0 && !domains.includes(match[1] ?? '')) {
        return { sender_email: `Debe ser de uno de sus dominios: ${domains.join(', ')}.` };
    }
    return {};
}

/** Client-side check of one step; step 5 re-checks all of them. Empty object = valid. */
export function validateStep(step: WizardStep, data: WizardData): WizardErrors {
    switch (step) {
        case 1:
            return companyErrors(data);
        case 2:
            return microsoftErrors(data);
        case 3:
            return brandErrors(data);
        case 4:
            return senderErrors(data);
        case 5:
            return { ...companyErrors(data), ...microsoftErrors(data), ...brandErrors(data), ...senderErrors(data) };
    }
}

const FIELD_STEP: Record<string, WizardStep> = {
    name: 1,
    short_name: 1,
    slug: 1,
    domains: 1,
    tenant_id: 2,
    logo: 3,
    logo_dark: 3,
    colors: 3,
    sender_email: 4,
};

/** Earliest step that owns one of the (server) errors; unknown keys go to the review step. */
export function firstStepWithErrors(errors: Record<string, string>): WizardStep | null {
    const keys = Object.keys(errors);
    if (keys.length === 0) return null;
    const steps = keys.map((key) => FIELD_STEP[key] ?? FIELD_STEP[key.split('.')[0] ?? ''] ?? 5);
    return Math.min(...steps) as WizardStep;
}

/**
 * Admin-consent URL from the server template (with a `{tenant}` slot), or
 * `null` while the tenant is not a GUID.
 */
export function adminConsentUrl(template: string, tenantId: string): string | null {
    const tenant = tenantId.trim().toLowerCase();
    if (!GUID.test(tenant)) return null;
    return template.replace('{tenant}', encodeURIComponent(tenant));
}
