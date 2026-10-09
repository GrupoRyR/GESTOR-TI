import { describe, expect, it } from 'vitest';
import { renderMarkdown } from './markdown';

describe('renderMarkdown', () => {
    it('renders headings, emphasis, lists and tables', () => {
        const html = renderMarkdown('## Cómo entrar\n\n1. Abre **Mis apps**.\n\n| A | B |\n|---|---|\n| 1 | 2 |');
        expect(html).toContain('<h2>Cómo entrar</h2>');
        expect(html).toContain('<strong>Mis apps</strong>');
        expect(html).toContain('<ol>');
        expect(html).toContain('<table>');
    });

    it('escapes raw HTML instead of rendering it', () => {
        const html = renderMarkdown('Hola <script>alert(1)</script> <img src=x onerror=alert(1)>');
        expect(html).not.toContain('<script');
        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;script&gt;');
    });

    it('does not turn javascript: or data: URLs into links', () => {
        const html = renderMarkdown('[clic](javascript:alert(1)) [dato](data:text/html;base64,PHNjcmlwdD4=) [vb](vbscript:msgbox)');
        expect(html).not.toMatch(/href="(javascript|data|vbscript):/i);
        expect(html).not.toContain('<a');
    });

    it('neutralizes obfuscated javascript: links', () => {
        const html = renderMarkdown('[x](JaVaScRiPt:alert(1)) [y](&#106;avascript:alert(1))');
        expect(html).not.toMatch(/href="\s*javascript/i);
        expect(html).not.toMatch(/href="&#106;/i);
    });

    it('opens safe links in a new tab without leaking the opener', () => {
        const html = renderMarkdown('[Vercel](https://vercel.com)');
        expect(html).toContain('href="https://vercel.com"');
        expect(html).toContain('rel="noopener noreferrer"');
        expect(html).toContain('target="_blank"');
    });

    it('linkifies bare URLs with the same attributes', () => {
        const html = renderMarkdown('Mira https://github.com/ryr/visualizador-cartera');
        expect(html).toContain('<a href="https://github.com/ryr/visualizador-cartera"');
        expect(html).toContain('rel="noopener noreferrer"');
    });

    it('returns an empty string for blank input', () => {
        expect(renderMarkdown('   ')).toBe('');
    });
});
