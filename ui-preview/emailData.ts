/**
 * Email templates for the gallery's "Correos" section: the raw files from
 * `resources/emails/` (found by glob) plus their sample data. Logos use the
 * SVGs in `resources/brand/` as a stand-in for the PNGs the backend serves.
 */
import logoDerivadosDark from '../resources/brand/logo-derivados-dark.svg?url';
import logoDerivados from '../resources/brand/logo-derivados.svg?url';
import logoSegurexDark from '../resources/brand/logo-segurex-dark.svg?url';
import logoSegurex from '../resources/brand/logo-segurex.svg?url';
import samplesJson from '../resources/emails/samples.json';
import type { EmailData, EmailSamples } from './lib/emailRender';

const htmlFiles = import.meta.glob<string>('../resources/emails/*.html', { query: '?raw', import: 'default', eager: true });
const textFiles = import.meta.glob<string>('../resources/emails/*.txt', { query: '?raw', import: 'default', eager: true });

export const emailSamples = samplesJson as EmailSamples;

function byName(files: Record<string, string>): Map<string, string> {
    return new Map(Object.entries(files).map(([path, source]) => [path.replace(/^.*\/([^/]+)\.(html|txt)$/, '$1'), source]));
}

const htmlByName = byName(htmlFiles);
const textByName = byName(textFiles);

export interface EmailTemplateFiles {
    key: string;
    label: string;
    html: string;
    text: string | null;
}

/** Templates in samples.json order; a template file without samples is skipped. */
export const emailTemplates: EmailTemplateFiles[] = Object.entries(emailSamples.templates).flatMap(([key, entry]) => {
    const html = htmlByName.get(key);
    return html === undefined ? [] : [{ key, label: entry.label, html, text: textByName.get(key) ?? null }];
});

export type EmailCompany = 'segurex' | 'derivados';

const absolute = (url: string) => new URL(url, document.baseURI).href;

/** Preview-only logo URLs (the srcdoc frame needs absolute ones). */
export function previewLogos(company: EmailCompany): EmailData {
    return company === 'segurex'
        ? { logo_url: absolute(logoSegurex), logo_dark_url: absolute(logoSegurexDark) }
        : { logo_url: absolute(logoDerivados), logo_dark_url: absolute(logoDerivadosDark) };
}
