/**
 * Tiny renderer for the email templates in `resources/emails/`, used by the
 * gallery preview and the rule tests. It understands only the markers the
 * templates use (the backend maps them to Blade):
 *
 *   {{ name }} / {{ item.field }}            escaped value (like Blade `{{ }}`)
 *   <!-- @if flag --> … <!-- @else --> … <!-- @endif -->
 *   <!-- @optional flag --> … <!-- @endoptional -->
 *   <!-- @foreach list as item --> … <!-- @endforeach -->
 *
 * A missing variable is left as `{{ name }}` so gaps in sample data show up.
 */

export type EmailData = Record<string, unknown>;

export type TemplateNode =
    | { type: 'text'; value: string }
    | { type: 'var'; path: string; raw: string }
    | { type: 'if'; name: string; then: TemplateNode[]; else: TemplateNode[] }
    | { type: 'optional'; name: string; body: TemplateNode[] }
    | { type: 'foreach'; list: string; alias: string; body: TemplateNode[] };

const TOKEN = /<!--\s*@(if|else|endif|optional|endoptional|foreach|endforeach)\b\s*(.*?)\s*-->|\{\{\s*([a-zA-Z_][\w.]*)\s*\}\}/g;

type Frame =
    | { kind: 'root'; nodes: TemplateNode[] }
    | { kind: 'if'; node: Extract<TemplateNode, { type: 'if' }>; inElse: boolean }
    | { kind: 'optional'; node: Extract<TemplateNode, { type: 'optional' }> }
    | { kind: 'foreach'; node: Extract<TemplateNode, { type: 'foreach' }> };

function target(frame: Frame): TemplateNode[] {
    switch (frame.kind) {
        case 'root':
            return frame.nodes;
        case 'if':
            return frame.inElse ? frame.node.else : frame.node.then;
        case 'optional':
        case 'foreach':
            return frame.node.body;
    }
}

function describeFrame(frame: Frame): string {
    switch (frame.kind) {
        case 'root':
            return 'template';
        case 'if':
            return `@if ${frame.node.name}`;
        case 'optional':
            return `@optional ${frame.node.name}`;
        case 'foreach':
            return `@foreach ${frame.node.list} as ${frame.node.alias}`;
    }
}

export function parseTemplate(source: string): TemplateNode[] {
    const root: Frame = { kind: 'root', nodes: [] };
    const stack: Frame[] = [root];
    const top = () => stack[stack.length - 1] as Frame;
    let last = 0;

    const close = (kind: Frame['kind'], marker: string) => {
        const frame = top();
        if (frame.kind !== kind) throw new Error(`Unexpected <!-- @${marker} --> inside ${describeFrame(frame)}`);
        stack.pop();
    };

    for (const match of source.matchAll(TOKEN)) {
        const index = match.index ?? 0;
        const [, marker, argument = '', variable] = match;
        let textEnd = index;
        let next = index + match[0].length;
        // A block marker alone on its line takes the whole line with it (as Blade
        // directives do), so lists and optional blocks leave no blank lines.
        if (marker !== undefined) {
            const lineStart = source.lastIndexOf('\n', index - 1) + 1;
            const newline = /^\r?\n/.exec(source.slice(next));
            if (lineStart >= last && newline !== null && /^[ \t]*$/.test(source.slice(lineStart, index))) {
                textEnd = lineStart;
                next += newline[0].length;
            }
        }
        if (textEnd > last) target(top()).push({ type: 'text', value: source.slice(last, textEnd) });
        last = next;

        if (variable !== undefined) {
            target(top()).push({ type: 'var', path: variable, raw: match[0] });
            continue;
        }
        switch (marker) {
            case 'if': {
                const node = { type: 'if' as const, name: argument, then: [], else: [] };
                target(top()).push(node);
                stack.push({ kind: 'if', node, inElse: false });
                break;
            }
            case 'else': {
                const frame = top();
                if (frame.kind !== 'if' || frame.inElse) throw new Error('Unexpected <!-- @else --> outside an @if');
                frame.inElse = true;
                break;
            }
            case 'endif':
                close('if', 'endif');
                break;
            case 'optional': {
                const node = { type: 'optional' as const, name: argument, body: [] };
                target(top()).push(node);
                stack.push({ kind: 'optional', node });
                break;
            }
            case 'endoptional':
                close('optional', 'endoptional');
                break;
            case 'foreach': {
                const parts = /^([a-zA-Z_][\w.]*)\s+as\s+([a-zA-Z_]\w*)$/.exec(argument);
                if (parts === null) throw new Error(`Malformed <!-- @foreach ${argument} -->`);
                const node = { type: 'foreach' as const, list: parts[1] as string, alias: parts[2] as string, body: [] };
                target(top()).push(node);
                stack.push({ kind: 'foreach', node });
                break;
            }
            case 'endforeach':
                close('foreach', 'endforeach');
                break;
        }
    }
    if (last < source.length) target(top()).push({ type: 'text', value: source.slice(last) });
    if (stack.length > 1) throw new Error(`Unclosed ${describeFrame(top())}`);
    return root.nodes;
}

// ---- render ---------------------------------------------------------------

export function escapeHtml(value: string): string {
    return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function lookup(scopes: EmailData[], path: string): unknown {
    const [head, ...rest] = path.split('.');
    let value: unknown;
    for (let index = scopes.length - 1; index >= 0; index--) {
        const scope = scopes[index] as EmailData;
        if (Object.hasOwn(scope, head as string)) {
            value = scope[head as string];
            break;
        }
    }
    for (const key of rest) {
        value = typeof value === 'object' && value !== null ? (value as EmailData)[key] : undefined;
    }
    return value;
}

function truthy(value: unknown): boolean {
    if (Array.isArray(value)) return value.length > 0;
    return value !== undefined && value !== null && value !== false && value !== '' && value !== 0;
}

function renderNodes(nodes: TemplateNode[], scopes: EmailData[], escape: boolean): string {
    let out = '';
    for (const node of nodes) {
        switch (node.type) {
            case 'text':
                out += node.value;
                break;
            case 'var': {
                const value = lookup(scopes, node.path);
                if (value === undefined || value === null) out += node.raw;
                else out += escape ? escapeHtml(String(value)) : String(value);
                break;
            }
            case 'if':
                out += renderNodes(truthy(lookup(scopes, node.name)) ? node.then : node.else, scopes, escape);
                break;
            case 'optional':
                if (truthy(lookup(scopes, node.name))) out += renderNodes(node.body, scopes, escape);
                break;
            case 'foreach': {
                const list = lookup(scopes, node.list);
                if (!Array.isArray(list)) break;
                for (const item of list) out += renderNodes(node.body, [...scopes, { [node.alias]: item }], escape);
                break;
            }
        }
    }
    return out;
}

export function renderTemplate(source: string, data: EmailData, options: { escape?: boolean } = {}): string {
    return renderNodes(parseTemplate(source), [data], options.escape ?? true);
}

// ---- inspection (rule tests) ------------------------------------------------

export interface BlockRef {
    kind: 'if' | 'optional' | 'foreach';
    name: string;
    branch?: 'then' | 'else';
}

export interface VariableUse {
    /** Top-level name, or `list[].field` for a field of a loop item. */
    name: string;
    blocks: BlockRef[];
}

/** Every variable and block flag in document order, with the blocks around it. */
export function collectVariables(nodes: TemplateNode[]): VariableUse[] {
    const uses: VariableUse[] = [];
    const walk = (list: TemplateNode[], blocks: BlockRef[], aliases: Map<string, string>) => {
        const nameOf = (path: string) => {
            const [head, ...rest] = path.split('.');
            const loop = aliases.get(head as string);
            if (loop === undefined) return head as string;
            return rest.length > 0 ? `${loop}[].${rest.join('.')}` : `${loop}[]`;
        };
        for (const node of list) {
            switch (node.type) {
                case 'text':
                    break;
                case 'var':
                    uses.push({ name: nameOf(node.path), blocks });
                    break;
                case 'if':
                    uses.push({ name: nameOf(node.name), blocks });
                    walk(node.then, [...blocks, { kind: 'if', name: node.name, branch: 'then' }], aliases);
                    walk(node.else, [...blocks, { kind: 'if', name: node.name, branch: 'else' }], aliases);
                    break;
                case 'optional':
                    uses.push({ name: nameOf(node.name), blocks });
                    walk(node.body, [...blocks, { kind: 'optional', name: node.name }], aliases);
                    break;
                case 'foreach':
                    uses.push({ name: nameOf(node.list), blocks });
                    walk(node.body, [...blocks, { kind: 'foreach', name: node.list }], new Map(aliases).set(node.alias, nameOf(node.list)));
                    break;
            }
        }
    };
    walk(nodes, [], new Map());
    // A plain string item (`{{ item }}`) is covered by its list.
    return uses.filter((use) => !use.name.endsWith('[]'));
}

/** Value of a `<!-- key: value -->` header comment (subject, from, to). */
export function readHeaderComment(source: string, key: string): string | null {
    const match = new RegExp(`<!--\\s*${key}:\\s*(.*?)\\s*-->`).exec(source);
    return match?.[1] ?? null;
}

/** The hidden preview text, without the spacer entities that pad it. */
export function readPreheader(source: string): string | null {
    const match = /<div class="em-preheader"[^>]*>([\s\S]*?)<\/div>/.exec(source);
    if (match?.[1] === undefined) return null;
    return match[1].replace(/(&#847;|&zwnj;|&nbsp;|&#8199;|&#65279;)/g, '').trim();
}

// ---- client simulation (gallery) ------------------------------------------

/** Finds `@media (prefers-color-scheme: dark) { … }` and returns its bounds and inner rules. */
function findDarkMedia(html: string): { start: number; end: number; inner: string } | null {
    const start = html.search(/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{/);
    if (start === -1) return null;
    const open = html.indexOf('{', start);
    let depth = 0;
    for (let index = open; index < html.length; index++) {
        if (html[index] === '{') depth++;
        else if (html[index] === '}') {
            depth--;
            if (depth === 0) return { start, end: index + 1, inner: html.slice(open + 1, index).trim() };
        }
    }
    return null;
}

/**
 * Pins the preview to a light or dark mail client regardless of the viewer's
 * OS theme: light drops the dark rules, dark applies them unconditionally
 * (what Apple Mail or Outlook do in dark mode).
 */
export function forceClientScheme(html: string, scheme: 'light' | 'dark'): string {
    const media = findDarkMedia(html);
    if (media === null) return html;
    const without = html.slice(0, media.start) + html.slice(media.end);
    if (scheme === 'light') return without;
    return without.replace('</head>', `<style data-client="dark">${media.inner}</style></head>`);
}

// ---- sample data -----------------------------------------------------------

export interface EmailSampleTemplate {
    label: string;
    /** Company of the approved board. */
    company: string;
    /** Who receives it in the sample (display only). */
    to: string;
    /** When it is sent and to whom (display only). */
    when?: string;
    data: EmailData;
    /** Report only: the version for recipients without `ai.costs.view`. */
    without_costs?: { to?: string; data: EmailData };
}

export interface EmailSamples {
    shared: EmailData;
    companies: Record<string, EmailData>;
    templates: Record<string, EmailSampleTemplate>;
}

export interface SampleOptions {
    company?: string;
    withoutCosts?: boolean;
    extra?: EmailData;
}

export function buildSampleData(samples: EmailSamples, template: string, options: SampleOptions): { to: string; data: EmailData } {
    const entry = samples.templates[template];
    if (entry === undefined) throw new Error(`Unknown email template: ${template}`);
    const company = samples.companies[options.company ?? entry.company] ?? samples.companies[entry.company] ?? {};
    const override = options.withoutCosts === true ? entry.without_costs : undefined;
    return {
        to: override?.to ?? entry.to,
        data: { ...samples.shared, ...company, ...entry.data, ...(override?.data ?? {}), ...(options.extra ?? {}) },
    };
}
