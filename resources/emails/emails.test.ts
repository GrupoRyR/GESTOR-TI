/**
 * Rule tests for the email templates. They read the files as the backend will
 * (plain text) and check the email-safety and privacy rules from the brief.
 */
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
    collectVariables,
    parseTemplate,
    readHeaderComment,
    readPreheader,
    renderTemplate,
    buildSampleData,
    type EmailSamples,
    type VariableUse,
} from '../../ui-preview/lib/emailRender';

const dir = (file: string) => fileURLToPath(new URL(file, import.meta.url));
const read = (file: string) => readFileSync(dir(file), 'utf8');

const TEMPLATES = [
    'new-request',
    'request-received',
    'status-changed',
    'it-question',
    'delivered',
    'requirement',
    'project-report',
] as const;
const REQUESTER_TEMPLATES = ['request-received', 'status-changed', 'it-question', 'delivered'];
/** Variable names that carry money, time spent, rates or internal notes. */
const SENSITIVE = /(^|[_.\]])(cost|costs|hours|budget|rate|rates|internal)(_|$)/;

const samples = JSON.parse(read('./samples.json')) as EmailSamples;
const readme = read('./README.md');

/** Every `{{ name }}` and block flag a file uses, with loop fields as `list[].field`. */
function uses(source: string): VariableUse[] {
    return collectVariables(parseTemplate(source));
}

function imgTags(html: string): string[] {
    return html.match(/<img\b[^>]*>/gi) ?? [];
}

describe.each(TEMPLATES)('%s.html', (name) => {
    const html = read(`./${name}.html`);

    it('is a complete, email-safe document', () => {
        expect(html.trimStart().toLowerCase().startsWith('<!doctype html>')).toBe(true);
        expect(html).toMatch(/<meta charset="utf-8">/i);
        expect(html).toMatch(/<meta name="viewport" content="width=device-width, initial-scale=1">/);
        expect(html).toMatch(/<meta name="color-scheme" content="light dark">/);
        expect(html).toMatch(/<html lang="es"/);
    });

    it('has no SVG, scripts, external CSS or web fonts', () => {
        expect(html).not.toMatch(/<svg\b/i);
        expect(html).not.toMatch(/<script\b/i);
        expect(html).not.toMatch(/<link\b[^>]*stylesheet/i);
        expect(html).not.toMatch(/@import|@font-face|fonts\.googleapis/i);
        expect(html).not.toMatch(/display:\s*(flex|grid)/i);
        expect(html).toContain("Figtree, 'Segoe UI', Arial, sans-serif");
    });

    it('keeps the layout in role="presentation" tables, at most 600 px wide', () => {
        const tables = html.match(/<table\b[^>]*>/gi) ?? [];
        expect(tables.length).toBeGreaterThan(0);
        for (const table of tables) expect(table).toContain('role="presentation"');
        expect(html).toContain('max-width: 600px');
        for (const match of html.matchAll(/\bwidth="(\d+)"/g)) expect(Number(match[1])).toBeLessThanOrEqual(600);
        for (const match of html.matchAll(/[^-]width:\s*(\d+)px/g)) expect(Number(match[1])).toBeLessThanOrEqual(600);
    });

    it('gives every image alt, width and height, and has no tracking pixels', () => {
        const images = imgTags(html);
        expect(images.length).toBeGreaterThan(0);
        for (const img of images) {
            expect(img).toMatch(/\balt="[^"]+"/);
            expect(img).toMatch(/\bwidth="[^"]+"/);
            expect(img).toMatch(/\bheight="[^"]+"/);
            expect(img).not.toMatch(/\b(width|height)="[01]"/);
            expect(img).not.toMatch(/\.svg/i);
        }
    });

    it('documents subject and sender at the top, and has a hidden preheader', () => {
        const subject = readHeaderComment(html, 'subject');
        expect(subject).toMatch(/^\[\{\{ (request_key|project_key) \}\}\] /);
        expect(readHeaderComment(html, 'from')).toBe('{{ company_sender }}');
        expect(html.indexOf('<!-- subject:')).toBeLessThan(html.indexOf('<head>'));
        expect(readPreheader(html)?.length ?? 0).toBeGreaterThan(10);
        expect(html).toMatch(/class="em-preheader" style="display: none;/);
    });

    it('has one primary button to {{ action_url }} plus the plain fallback link', () => {
        expect(html).toMatch(/<a class="em-button" href="\{\{ action_url \}\}"[^>]*>[^<]+<\/a>/);
        expect(html.match(/class="em-button"/g)).toHaveLength(1);
        expect(html).toMatch(
            /Si el botón no funciona, copia este enlace:<br>\s*<a [^>]*href="\{\{ action_url \}\}"[^>]*>\{\{ action_url \}\}<\/a>/,
        );
    });

    it('has the brand header and the footer with "Cambiar mis avisos"', () => {
        expect(html).toContain('src="{{ logo_url }}"');
        expect(html).toContain('background: {{ brand_color }}');
        expect(html).toContain('Gestor TI · Grupo RYR');
        expect(html).toMatch(/Recibes (este correo|este informe) porque/);
        expect(html).toMatch(/<a [^>]*href="\{\{ preferences_url \}\}"[^>]*>Cambiar mis avisos<\/a>/);
    });

    it('documents every variable in the README and gives it sample data', () => {
        const names = new Set(uses(html).map((use) => use.name));
        for (const company of Object.keys(samples.companies)) {
            const data = buildSampleData(samples, name, { company }).data;
            for (const variable of names) {
                const [list, field] = variable.split('[].');
                expect(data, `${variable} missing in samples.json (${company})`).toHaveProperty(list as string);
                if (field !== undefined) {
                    const items = data[list as string];
                    expect(Array.isArray(items), `${list} must be a list`).toBe(true);
                    for (const item of items as unknown[]) {
                        if (typeof item === 'object' && item !== null) expect(item, `${variable} in samples.json`).toHaveProperty(field);
                    }
                }
            }
        }
        for (const variable of names) {
            expect(readme, `${variable} is not documented in README.md`).toContain(`\`${variable.replace('[].', '.')}\``);
        }
    });

    it('renders with its sample data, leaving no unresolved variables', () => {
        for (const company of Object.keys(samples.companies)) {
            const { data } = buildSampleData(samples, name, { company, extra: { reply_by_email: true } });
            expect(renderTemplate(html, data)).not.toMatch(/\{\{/);
        }
    });

    it('has a plain-text version with the same subject, link and footer', () => {
        const file = `./${name}.txt`;
        expect(existsSync(dir(file))).toBe(true);
        const text = read(file);
        expect(text).not.toMatch(/<(?!!--)[a-z]/i);
        expect(readHeaderComment(text, 'subject')).toBe(readHeaderComment(html, 'subject'));
        expect(text).toContain('{{ action_url }}');
        expect(text).toContain('{{ preferences_url }}');
        expect(text).toContain('Gestor TI · Grupo RYR');
        const { data } = buildSampleData(samples, name, { company: 'segurex', extra: { reply_by_email: true } });
        expect(renderTemplate(text, data, { escape: false })).not.toMatch(/\{\{/);
    });
});

describe('privacy rules', () => {
    it.each(REQUESTER_TEMPLATES)('%s (to the requester) has no cost, rate or internal-note variables', (name) => {
        for (const file of [`./${name}.html`, `./${name}.txt`]) {
            const names = uses(read(file)).map((use) => use.name);
            expect(names.filter((variable) => SENSITIVE.test(variable) || variable === 'can_view_costs')).toEqual([]);
            expect(read(file)).not.toMatch(/\$\s?\d/);
        }
    });

    it.each(['project-report.html', 'project-report.txt'])('%s keeps cost variables inside @if can_view_costs', (file) => {
        const all = uses(read(`./${file}`));
        // The guard flag itself is the only cost-named variable allowed outside.
        const sensitive = all.filter((use) => SENSITIVE.test(use.name) && use.name !== 'can_view_costs');
        expect(sensitive.length).toBeGreaterThan(5);
        for (const use of sensitive) {
            const guarded = use.blocks.some((block) => block.kind === 'if' && block.name === 'can_view_costs' && block.branch === 'then');
            expect(guarded, `${use.name} outside the costs block`).toBe(true);
        }
    });

    it('renders the report without any amount when can_view_costs is false', () => {
        const html = read('./project-report.html');
        const { data } = buildSampleData(samples, 'project-report', { company: 'segurex', withoutCosts: true });
        const out = renderTemplate(html, data);
        expect(out).not.toContain('Tiempo y costo');
        expect(out).not.toMatch(/\$\s?\d/);
        const withCosts = renderTemplate(html, buildSampleData(samples, 'project-report', { company: 'segurex' }).data);
        expect(withCosts).toContain('Tiempo y costo');
        expect(withCosts).toContain('$ 14.200.000');
    });

    it('wraps the reply-by-email box in an @optional block', () => {
        const html = read('./it-question.html');
        expect(html).toMatch(/<!-- @optional reply_by_email -->[\s\S]*También puedes responder este correo[\s\S]*<!-- @endoptional -->/);
        const { data } = buildSampleData(samples, 'it-question', { company: 'derivados' });
        expect(renderTemplate(html, { ...data, reply_by_email: false })).not.toContain('También puedes responder');
    });

    it('offers five one-click rating links on the delivery email', () => {
        const html = read('./delivered.html');
        for (const score of [1, 2, 3, 4, 5]) expect(html).toContain(`href="{{ rating_url_${score} }}"`);
    });
});

describe('partials', () => {
    it.each(['header', 'footer', 'button'])('%s.html exists and is email-safe', (name) => {
        const html = read(`./partials/${name}.html`);
        expect(html).not.toMatch(/<svg\b|<script\b/i);
        for (const img of imgTags(html)) expect(img).toMatch(/\balt="[^"]+"/);
    });

    it('button is bulletproof: a table cell plus a padded link, 44 px tall', () => {
        const html = read('./partials/button.html');
        expect(html).toContain('role="presentation"');
        expect(html).toMatch(/<a class="em-button" href="\{\{ action_url \}\}"[^>]*padding: 12px 24px;[^>]*line-height: 20px;/);
        expect(html).toContain('color: #FFFFFF');
        expect(html).toContain('background: {{ brand_color }}');
    });
});
