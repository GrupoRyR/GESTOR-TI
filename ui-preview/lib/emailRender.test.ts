import { describe, expect, it } from 'vitest';
import {
    buildSampleData,
    collectVariables,
    forceClientScheme,
    parseTemplate,
    readHeaderComment,
    readPreheader,
    renderTemplate,
    type EmailSamples,
} from './emailRender';

describe('renderTemplate', () => {
    it('replaces variables and escapes HTML like Blade does', () => {
        expect(renderTemplate('<b>{{ name }}</b> · {{name}}', { name: 'Ana & <Luis>' })).toBe(
            '<b>Ana &amp; &lt;Luis&gt;</b> · Ana &amp; &lt;Luis&gt;',
        );
    });

    it('can render plain text without escaping', () => {
        expect(renderTemplate('Hola, {{ name }}', { name: 'A & B' }, { escape: false })).toBe('Hola, A & B');
    });

    it('turns numbers into text and keeps missing variables visible', () => {
        expect(renderTemplate('{{ hours }} h · {{ missing }}', { hours: 92 })).toBe('92 h · {{ missing }}');
    });

    it('renders @if with an optional @else branch', () => {
        const source = 'A<!-- @if show -->B<!-- @else -->C<!-- @endif -->D';
        expect(renderTemplate(source, { show: true })).toBe('ABD');
        expect(renderTemplate(source, { show: false })).toBe('ACD');
        expect(renderTemplate(source, { show: [] })).toBe('ACD');
        expect(renderTemplate(source, {})).toBe('ACD');
    });

    it('renders @optional blocks only when their flag is truthy', () => {
        const source = 'x<!-- @optional reply_by_email -->reply<!-- @endoptional -->y';
        expect(renderTemplate(source, { reply_by_email: true })).toBe('xreplyy');
        expect(renderTemplate(source, { reply_by_email: false })).toBe('xy');
    });

    it('repeats @foreach bodies with the item in scope, including dotted fields', () => {
        const source = '<!-- @foreach risks as risk --><li>{{ risk.title }}: {{ risk.text }} ({{ project }})</li><!-- @endforeach -->';
        expect(
            renderTemplate(source, {
                project: 'P-07',
                risks: [
                    { title: 'Fecha', text: '14 nov' },
                    { title: 'Dato', text: 'SAP' },
                ],
            }),
        ).toBe('<li>Fecha: 14 nov (P-07)</li><li>Dato: SAP (P-07)</li>');
    });

    it('supports plain string lists and @if on item fields', () => {
        const source =
            '<!-- @foreach items as item -->[{{ item }}]<!-- @endforeach --><!-- @foreach phases as phase --><!-- @if phase.note -->{{ phase.note }}<!-- @endif --><!-- @endforeach -->';
        expect(renderTemplate(source, { items: ['a', 'b'], phases: [{ note: 'n1' }, { note: '' }] })).toBe('[a][b]n1');
    });

    it('drops the whole line of a block marker that stands alone on it, like Blade', () => {
        const source = 'Lista:\n  <!-- @foreach items as item -->\n- {{ item }}\r\n  <!-- @endforeach -->\nFin <!-- @if x -->sí<!-- @endif -->\n';
        expect(renderTemplate(source, { items: ['a', 'b'], x: true }, { escape: false })).toBe('Lista:\n- a\r\n- b\r\nFin sí\n');
    });

    it('rejects unbalanced blocks', () => {
        expect(() => renderTemplate('<!-- @if a -->x', {})).toThrow(/@if a/);
        expect(() => renderTemplate('x<!-- @endforeach -->', {})).toThrow(/@endforeach/);
    });
});

describe('collectVariables', () => {
    it('lists top-level names and loop fields with the block path they live in', () => {
        const nodes = parseTemplate(
            '{{ a }}<!-- @if can_view_costs -->{{ cost_used }}<!-- @else -->{{ summary }}<!-- @endif --><!-- @foreach risks as risk -->{{ risk.text }}{{ a }}<!-- @endforeach -->',
        );
        const uses = collectVariables(nodes);
        expect(uses.map((use) => use.name)).toEqual(['a', 'can_view_costs', 'cost_used', 'summary', 'risks', 'risks[].text', 'a']);
        const cost = uses.find((use) => use.name === 'cost_used');
        expect(cost?.blocks).toEqual([{ kind: 'if', name: 'can_view_costs', branch: 'then' }]);
        const summary = uses.find((use) => use.name === 'summary');
        expect(summary?.blocks).toEqual([{ kind: 'if', name: 'can_view_costs', branch: 'else' }]);
    });
});

describe('header comments and preheader', () => {
    const source =
        '<!doctype html>\n<!-- subject: [{{ request_key }}] Hola -->\n<!-- from: {{ company_sender }} -->\n<div class="em-preheader" style="display: none">Texto previo {{ x }}&#847;&zwnj;</div>';

    it('reads the subject and from comments', () => {
        expect(readHeaderComment(source, 'subject')).toBe('[{{ request_key }}] Hola');
        expect(readHeaderComment(source, 'from')).toBe('{{ company_sender }}');
        expect(readHeaderComment(source, 'to')).toBeNull();
    });

    it('reads the preheader text', () => {
        expect(readPreheader(source)).toBe('Texto previo {{ x }}');
    });
});

describe('forceClientScheme', () => {
    const html =
        '<html><head><style>.a{color:red}@media (prefers-color-scheme: dark) { .em-bg { background: #121315 !important; } .x{color:#fff} }.b{color:blue}</style></head><body></body></html>';

    it('dark: applies the dark rules unconditionally', () => {
        const out = forceClientScheme(html, 'dark');
        expect(out).toContain('.em-bg { background: #121315 !important; }');
        expect(out).toMatch(/<style data-client="dark">[^<]*\.em-bg \{ background: #121315 !important; \}[^<]*<\/style><\/head>/);
        expect(out).not.toContain('prefers-color-scheme');
    });

    it('light: drops the dark rules so the OS theme cannot leak in', () => {
        const out = forceClientScheme(html, 'light');
        expect(out).not.toContain('#121315');
        expect(out).toContain('.a{color:red}');
        expect(out).toContain('.b{color:blue}');
    });
});

describe('buildSampleData', () => {
    const samples: EmailSamples = {
        shared: { preferences_url: 'https://x/avisos' },
        companies: {
            segurex: { company_name: 'Segurex', brand_color: '#C4470F' },
            derivados: { company_name: 'Derivados', brand_color: '#183F94' },
        },
        templates: {
            'project-report': {
                label: 'Informe',
                company: 'segurex',
                to: 'Jhon',
                data: { can_view_costs: true, why: 'con' },
                without_costs: { to: 'Gerencia', data: { can_view_costs: false, why: 'sin' } },
            },
        },
    };

    it('merges shared, company and template data', () => {
        const result = buildSampleData(samples, 'project-report', { company: 'derivados' });
        expect(result.to).toBe('Jhon');
        expect(result.data).toMatchObject({
            preferences_url: 'https://x/avisos',
            company_name: 'Derivados',
            can_view_costs: true,
            why: 'con',
        });
    });

    it('applies the without-costs override and extra values last', () => {
        const result = buildSampleData(samples, 'project-report', {
            company: 'segurex',
            withoutCosts: true,
            extra: { reply_by_email: true },
        });
        expect(result.to).toBe('Gerencia');
        expect(result.data).toMatchObject({ can_view_costs: false, why: 'sin', reply_by_email: true, company_name: 'Segurex' });
    });

    it('falls back to the template company and throws on unknown templates', () => {
        expect(buildSampleData(samples, 'project-report', {}).data.company_name).toBe('Segurex');
        expect(() => buildSampleData(samples, 'nope', {})).toThrow(/nope/);
    });
});
