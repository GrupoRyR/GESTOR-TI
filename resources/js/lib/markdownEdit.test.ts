import { describe, expect, it } from 'vitest';
import { applyMarkdownAction } from './markdownEdit';

describe('applyMarkdownAction', () => {
    it('wraps the selection in bold and keeps it selected', () => {
        const result = applyMarkdownAction('Abre Filtros ya', 5, 12, 'bold');
        expect(result.text).toBe('Abre **Filtros** ya');
        expect(result.text.slice(result.selectionStart, result.selectionEnd)).toBe('Filtros');
    });

    it('inserts a placeholder when nothing is selected', () => {
        const result = applyMarkdownAction('Hola ', 5, 5, 'italic');
        expect(result.text).toBe('Hola _texto_');
        expect(result.text.slice(result.selectionStart, result.selectionEnd)).toBe('texto');
    });

    it('prefixes every selected line for lists', () => {
        const result = applyMarkdownAction('uno\ndos', 0, 7, 'bullet');
        expect(result.text).toBe('- uno\n- dos');
        const numbered = applyMarkdownAction('uno\ndos', 0, 7, 'numbered');
        expect(numbered.text).toBe('1. uno\n2. dos');
    });

    it('turns the current line into a heading from anywhere in it', () => {
        const result = applyMarkdownAction('intro\nCómo entrar\nfin', 9, 9, 'heading');
        expect(result.text).toBe('intro\n## Cómo entrar\nfin');
    });

    it('builds a link with the selection as text and selects the URL', () => {
        const result = applyMarkdownAction('Pedir mejora', 0, 12, 'link');
        expect(result.text).toBe('[Pedir mejora](https://)');
        expect(result.text.slice(result.selectionStart, result.selectionEnd)).toBe('https://');
    });

    it('inserts a table template on its own line', () => {
        const result = applyMarkdownAction('Texto', 5, 5, 'table');
        expect(result.text.startsWith('Texto\n\n| Columna | Qué significa |\n|---|---|\n')).toBe(true);
    });

    it('wraps code', () => {
        expect(applyMarkdownAction('SELECT 1', 0, 8, 'code').text).toBe('`SELECT 1`');
    });
});
