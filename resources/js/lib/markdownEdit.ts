/**
 * Toolbar actions of the Markdown editor as pure text transforms: given the
 * text and the selection, return the new text and the selection to restore.
 */

export type MarkdownAction = 'heading' | 'bold' | 'italic' | 'bullet' | 'numbered' | 'link' | 'code' | 'table';

export interface EditResult {
    text: string;
    selectionStart: number;
    selectionEnd: number;
}

const PLACEHOLDER = 'texto';
const TABLE = '| Columna | Qué significa |\n|---|---|\n| | |\n';

function wrap(text: string, start: number, end: number, marker: string): EditResult {
    const selected = text.slice(start, end) || PLACEHOLDER;
    const next = text.slice(0, start) + marker + selected + marker + text.slice(end);
    const from = start + marker.length;
    return { text: next, selectionStart: from, selectionEnd: from + selected.length };
}

function lineBounds(text: string, start: number, end: number) {
    const from = text.lastIndexOf('\n', start - 1) + 1;
    const newline = text.indexOf('\n', end);
    return { from, to: newline === -1 ? text.length : newline };
}

function prefixLines(text: string, start: number, end: number, prefix: (index: number) => string): EditResult {
    const { from, to } = lineBounds(text, start, end);
    const block = text
        .slice(from, to)
        .split('\n')
        .map((line, index) => prefix(index) + line)
        .join('\n');
    return { text: text.slice(0, from) + block + text.slice(to), selectionStart: from, selectionEnd: from + block.length };
}

export function applyMarkdownAction(text: string, start: number, end: number, action: MarkdownAction): EditResult {
    switch (action) {
        case 'bold':
            return wrap(text, start, end, '**');
        case 'italic':
            return wrap(text, start, end, '_');
        case 'code':
            return wrap(text, start, end, '`');
        case 'heading':
            return prefixLines(text, start, end, () => '## ');
        case 'bullet':
            return prefixLines(text, start, end, () => '- ');
        case 'numbered':
            return prefixLines(text, start, end, (index) => `${index + 1}. `);
        case 'link': {
            const label = text.slice(start, end) || 'enlace';
            const insert = `[${label}](https://)`;
            const urlStart = start + label.length + 3;
            return { text: text.slice(0, start) + insert + text.slice(end), selectionStart: urlStart, selectionEnd: urlStart + 8 };
        }
        case 'table': {
            const before = text.slice(0, start);
            const lead = before === '' ? '' : before.endsWith('\n\n') ? '' : before.endsWith('\n') ? '\n' : '\n\n';
            const next = before + lead + TABLE + text.slice(end);
            const cursor = before.length + lead.length + TABLE.length;
            return { text: next, selectionStart: cursor, selectionEnd: cursor };
        }
    }
}
