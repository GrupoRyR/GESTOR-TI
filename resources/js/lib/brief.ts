/**
 * "Brief para Claude": Markdown built from a template with the app's own
 * data. It does NOT call the AI API. The backend mirrors these builders for
 * the "Descargar .md" endpoint, so keep both in sync.
 *
 * Safety rule: builders read only the whitelisted fields of their input
 * types. Costs, rates, vault items, credentials and internal notes are never
 * read, even when the caller passes an object that carries them. Decisions
 * marked `private` and links that point to the vault are dropped.
 */
import type { EffortSize } from './priority';

export type BriefSectionKey = 'problem' | 'criteria' | 'product' | 'links' | 'tasks' | 'decisions';

export interface BriefSection {
    key: BriefSectionKey;
    label: string;
}

/** Panel order of the "Qué incluye" toggles. */
export const BRIEF_SECTIONS: readonly BriefSection[] = [
    { key: 'problem', label: 'Problema' },
    { key: 'criteria', label: 'Criterios de aceptación' },
    { key: 'product', label: 'Contexto del producto' },
    { key: 'links', label: 'Enlaces a documentación y repositorio' },
    { key: 'tasks', label: 'Tareas del proyecto' },
    { key: 'decisions', label: 'Decisiones' },
];

/** On by default for requests and tasks (4 de 6). Projects turn on all six. */
export const DEFAULT_BRIEF_SECTIONS: BriefSectionKey[] = ['problem', 'criteria', 'product', 'links'];

export const ALL_BRIEF_SECTIONS: BriefSectionKey[] = BRIEF_SECTIONS.map((section) => section.key);

// ---- inputs ---------------------------------------------------------------

export interface BriefProduct {
    name: string;
    version: string | null;
    /** "Next.js". */
    stack: string | null;
    /** "Vercel". */
    host: string | null;
    /** "SAP Business One vía [FUENTE]". */
    data_source: string | null;
}

export interface BriefLink {
    label: string;
    url: string;
}

export interface BriefTaskRow {
    key: string;
    title: string;
    /** Column label, e.g. "En revisión". */
    status: string;
    /** "PR #41", "Issue #57" or null ("sin issue"). */
    github: string | null;
}

export interface BriefDecision {
    text: string;
    /** Internal decisions never leave the app. */
    private?: boolean;
}

export interface RequestBriefInput {
    key: string;
    title: string;
    company: string;
    area: string;
    /** Person or area that asked ("Gerencia de cartera"). */
    requester: string;
    size: EffortSize | null;
    project_key: string | null;
    problem: string;
    current_process?: string | null;
    expected_result?: string | null;
    hours_per_week?: number | null;
    people?: number | null;
    criteria: string[];
    product: BriefProduct | null;
    links: BriefLink[];
    tasks?: BriefTaskRow[];
    decisions?: BriefDecision[];
}

export interface TaskBriefInput {
    key: string;
    title: string;
    project: { key: string; name: string };
    request: { key: string; title: string } | null;
    /** Column label, e.g. "En progreso". */
    status: string;
    assignees: string[];
    description: string;
    subtasks: { title: string; done: boolean }[];
    criteria: string[];
    product: BriefProduct | null;
    links: BriefLink[];
    pull_request?: { number: number; title: string; /** "abierto", "fusionado". */ state: string } | null;
    issue?: { number: number } | null;
    suggested_branch?: string | null;
    tasks?: BriefTaskRow[];
    decisions?: BriefDecision[];
}

export interface ProjectBriefInput {
    key: string;
    name: string;
    company: string;
    area: string;
    /** "En desarrollo". */
    status: string;
    /** "Construcción". */
    phase: string | null;
    request_keys: string[];
    objective: string;
    criteria: { text: string; done: boolean }[];
    product: BriefProduct | null;
    links: BriefLink[];
    tasks: BriefTaskRow[];
    decisions: BriefDecision[];
}

export interface ClaudeMdInput {
    project_name?: string | null;
    objective?: string | null;
    stack?: string | null;
    host?: string | null;
    data_source?: string | null;
    commands?: { command: string; description: string }[];
    conventions?: string[];
}

// ---- helpers --------------------------------------------------------------

const VAULT_PATTERN = /vault|b[oó]veda/i;

const numberFormat = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 1 });

function safeLinks(links: BriefLink[]): BriefLink[] {
    return links.filter((link) => !VAULT_PATTERN.test(link.url) && !VAULT_PATTERN.test(link.label));
}

function publicDecisions(decisions: BriefDecision[] | undefined): string[] {
    return (decisions ?? []).filter((decision) => decision.private !== true).map((decision) => decision.text.trim()).filter(Boolean);
}

function clean(items: string[]): string[] {
    return items.map((item) => item.trim()).filter(Boolean);
}

function cell(text: string): string {
    return text.replace(/\r?\n/g, ' ').replace(/\|/g, '\\|').trim();
}

function section(title: string, body: string | null): string[] {
    return body ? [`## ${title}`, body] : [];
}

function productBlock(product: BriefProduct | null): string | null {
    if (!product) return null;
    const lines = [`- Producto: ${[product.name, product.version].filter(Boolean).join(' · ')}`];
    if (product.stack) lines.push(`- Tecnología: ${product.host ? `${product.stack}, publicado en ${product.host}` : product.stack}`);
    if (product.data_source) lines.push(`- Datos: ${product.data_source}`);
    return lines.join('\n');
}

function linksBlock(links: BriefLink[], extra: string[] = []): string | null {
    const lines = [...safeLinks(links).map((link) => `- ${link.label}: ${link.url}`), ...extra];
    return lines.length > 0 ? lines.join('\n') : null;
}

function tasksBlock(tasks: BriefTaskRow[] | undefined): string | null {
    if (!tasks || tasks.length === 0) return null;
    const rows = tasks.map((task) => `| ${cell(`${task.key} ${task.title}`)} | ${cell(task.status)} | ${cell(task.github ?? 'sin issue')} |`);
    return ['| Tarea | Estado | GitHub |', '| --- | --- | --- |', ...rows].join('\n');
}

function listBlock(items: string[]): string | null {
    return items.length > 0 ? items.map((item) => `- ${item}`).join('\n') : null;
}

function numberedBlock(items: string[]): string | null {
    return items.length > 0 ? items.map((item, index) => `${index + 1}. ${item}`).join('\n') : null;
}

function checklist(items: { text: string; done: boolean }[]): string | null {
    const kept = items.filter((item) => item.text.trim());
    return kept.length > 0 ? kept.map((item) => `- [${item.done ? 'x' : ' '}] ${item.text.trim()}`).join('\n') : null;
}

function join(blocks: string[]): string {
    return `${blocks.filter((block) => block.trim() !== '').join('\n\n')}\n`;
}

function timeToday(hours: number | null | undefined, people: number | null | undefined, who: string): string | null {
    if (!hours || !people) return null;
    return `Hoy le toma ${numberFormat.format(hours)} h a la semana a ${people} ${people === 1 ? 'persona' : 'personas'} de ${who}.`;
}

// ---- builders -------------------------------------------------------------

/** Brief of a request (IT view): problem, criteria, product, links… */
export function buildRequestBrief(input: RequestBriefInput, sections: BriefSectionKey[] = DEFAULT_BRIEF_SECTIONS): string {
    const on = new Set(sections);
    const meta = [input.company, input.area, input.requester, input.size ? `Talla ${input.size}` : null, input.project_key ? `Proyecto ${input.project_key}` : null]
        .filter(Boolean)
        .join(' · ');

    const blocks = [`# ${input.key} · ${input.title}`, meta];

    if (on.has('problem')) {
        const time = timeToday(input.hours_per_week, input.people, input.requester);
        const problem = [input.problem.trim(), time].filter(Boolean).join(' ');
        const parts = [problem];
        if (input.current_process?.trim()) parts.push(`Cómo se hace hoy: ${input.current_process.trim()}`);
        if (input.expected_result?.trim()) parts.push(`Resultado esperado: ${input.expected_result.trim()}`);
        blocks.push(...section('Problema', parts.filter(Boolean).join('\n\n') || null));
    }
    if (on.has('criteria')) blocks.push(...section('Criterios de aceptación', numberedBlock(clean(input.criteria))));
    if (on.has('product')) blocks.push(...section('Contexto del producto', productBlock(input.product)));
    if (on.has('links')) blocks.push(...section('Enlaces', linksBlock(input.links)));
    if (on.has('tasks')) blocks.push(...section('Tareas del proyecto', tasksBlock(input.tasks)));
    if (on.has('decisions')) blocks.push(...section('Decisiones', listBlock(publicDecisions(input.decisions))));

    return join(blocks);
}

/** Brief of one task: what to do (subtasks), request criteria, PR/issue/branch. */
export function buildTaskBrief(input: TaskBriefInput, sections: BriefSectionKey[] = DEFAULT_BRIEF_SECTIONS): string {
    const on = new Set(sections);
    const assignees = input.assignees.length > 0 ? input.assignees.join(', ') : 'sin responsable';
    const meta = [
        `- Proyecto: ${input.project.key} ${input.project.name}`,
        input.request ? `- Solicitud: ${input.request.key} · ${input.request.title}` : null,
        `- Estado: ${input.status} · Responsable: ${assignees}`,
    ]
        .filter(Boolean)
        .join('\n');

    const blocks = [`# ${input.key} · ${input.title}`, meta];

    if (on.has('problem')) {
        const parts = [input.description.trim(), checklist(input.subtasks.map((subtask) => ({ text: subtask.title, done: subtask.done })))];
        blocks.push(...section('Qué hay que hacer', parts.filter(Boolean).join('\n\n') || null));
    }
    if (on.has('criteria')) {
        const title = input.request ? `Criterios de aceptación (${input.request.key})` : 'Criterios de aceptación';
        blocks.push(...section(title, numberedBlock(clean(input.criteria))));
    }
    if (on.has('product')) blocks.push(...section('Contexto del producto', productBlock(input.product)));
    if (on.has('links')) {
        const extra: string[] = [];
        if (input.pull_request) extra.push(`- PR relacionado: #${input.pull_request.number} ${input.pull_request.title} (${input.pull_request.state})`);
        if (input.issue) extra.push(`- Issue: #${input.issue.number}`);
        else extra.push(`- Issue: todavía no tiene${input.suggested_branch ? ` · rama sugerida ${input.suggested_branch}` : ''}`);
        blocks.push(...section('Enlaces', linksBlock(input.links, extra)));
    }
    if (on.has('tasks')) blocks.push(...section('Tareas del proyecto', tasksBlock(input.tasks)));
    if (on.has('decisions')) blocks.push(...section('Decisiones', listBlock(publicDecisions(input.decisions))));
    if (on.has('problem')) {
        const next = input.subtasks.find((subtask) => !subtask.done && subtask.title.trim());
        if (next) blocks.push(...section('Siguiente paso', `${next.title.trim().replace(/\.$/, '')}.`));
    }

    return join(blocks);
}

/** Brief of a whole project: objective, criteria progress, task table, decisions. */
export function buildProjectBrief(input: ProjectBriefInput, sections: BriefSectionKey[] = ALL_BRIEF_SECTIONS): string {
    const on = new Set(sections);
    const status = input.phase ? `${input.status}, fase ${input.phase}` : input.status;
    const origin = input.request_keys.length > 0 ? `nace de ${input.request_keys.join(', ')}` : null;
    const meta = [input.company, input.area, status, origin].filter(Boolean).join(' · ');

    const blocks = [`# ${input.key} · ${input.name}`, meta];

    if (on.has('problem')) blocks.push(...section('Objetivo', input.objective.trim() || null));
    if (on.has('criteria')) {
        const criteria = input.criteria.filter((criterion) => criterion.text.trim());
        const done = criteria.filter((criterion) => criterion.done).length;
        blocks.push(...section(`Criterios de aceptación · ${done} de ${criteria.length} cumplidos`, checklist(criteria)));
    }
    if (on.has('product')) blocks.push(...section('Contexto del producto', productBlock(input.product)));
    if (on.has('links')) blocks.push(...section('Enlaces', linksBlock(input.links)));
    if (on.has('tasks')) blocks.push(...section('Tareas del proyecto', tasksBlock(input.tasks)));
    if (on.has('decisions')) blocks.push(...section('Decisiones', listBlock(publicDecisions(input.decisions))));

    return join(blocks);
}

const DEFAULT_COMMANDS = [
    { command: 'npm install', description: 'instala dependencias' },
    { command: 'npm run dev', description: 'corre en local' },
    { command: 'npm test', description: 'corre las pruebas' },
];

const DEFAULT_CONVENTIONS = [
    'Rama `gia-123-descripcion`; la clave GIA va en el PR',
    'Commits convencionales: feat, fix, docs',
    'Textos de la app en español de Colombia',
];

/** Initial CLAUDE.md of a new project; bracket placeholders where data is missing. */
export function buildClaudeMd(input: ClaudeMdInput): string {
    const name = input.project_name?.trim() || '[NOMBRE DEL PROYECTO]';
    const objective = input.objective?.trim() || '[OBJETIVO, tomado de la solicitud aprobada]';
    const stack = input.stack?.trim() || '[STACK]';
    const host = input.host?.trim() || '[HOSTING]';
    const data = input.data_source?.trim() || '[FUENTE DE DATOS]';
    const commands = input.commands && input.commands.length > 0 ? input.commands : DEFAULT_COMMANDS;
    const conventions = input.conventions && input.conventions.length > 0 ? input.conventions : DEFAULT_CONVENTIONS;

    return join([
        `# CLAUDE.md · ${name}`,
        '## Objetivo',
        objective,
        '## Stack',
        [`- ${stack} · se publica en ${host}`, `- Datos: ${data}, solo lectura`].join('\n'),
        '## Comandos',
        commands.map((command) => `- \`${command.command}\` ${command.description}`).join('\n'),
        '## Convenciones',
        conventions.map((convention) => `- ${convention}`).join('\n'),
        '## Dónde vive la documentación',
        '- README.md, docs/ y Gestor TI > Documentación',
        '## Nunca subir secretos',
        ['- Claves en variables de entorno; .env* va en .gitignore', '- Contraseñas y accesos viven en la bóveda, no aquí'].join('\n'),
    ]);
}

export interface BriefStats {
    words: number;
    lines: number;
}

/** Words (Markdown markers excluded) and lines (final newline not counted). */
export function briefStats(markdown: string): BriefStats {
    if (markdown === '') return { words: 0, lines: 0 };
    const text = markdown.replace(/^\s*(?:[-*+]|\d+\.)\s+(?:\[[ xX]\]\s+)?/gm, '').replace(/^#{1,6}\s+/gm, '');
    const words = text.match(/\S*[\p{L}\p{N}]\S*/gu)?.length ?? 0;
    const lines = markdown.replace(/\n$/, '').split('\n').length;
    return { words, lines };
}
