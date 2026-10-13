import { describe, expect, it } from 'vitest';
import {
    BRIEF_SECTIONS,
    DEFAULT_BRIEF_SECTIONS,
    briefStats,
    buildClaudeMd,
    buildProjectBrief,
    buildRequestBrief,
    buildTaskBrief,
    briefFileName,
    claudeMdInputFromProject,
    omittedNote,
    repoNameError,
    withTaskGitHub,
    type ProjectBriefInput,
    type RequestBriefInput,
    type TaskBriefInput,
} from './brief';

const PRODUCT = { name: 'Visualizador de cartera', version: 'v3.1.0', stack: 'Next.js', host: 'Vercel', data_source: 'SAP Business One vía [FUENTE]' };
const LINKS = [
    { label: 'Repositorio', url: 'https://github.com/GrupoRyR/visualizador-cartera' },
    { label: 'Instrucciones para Claude (CLAUDE.md)', url: 'https://github.com/GrupoRyR/visualizador-cartera/blob/main/CLAUDE.md' },
    { label: 'Manual técnico y de despliegue', url: 'https://gestor.example/ai/docs/manual-tecnico' },
];

const REQUEST: RequestBriefInput = {
    key: 'GIA-139',
    title: 'Visualizador de cartera: filtro por asesor y zona',
    company: 'Segurex',
    area: 'Cartera',
    requester: 'Gerencia de cartera',
    size: 'L',
    project_key: 'P-07',
    problem: 'Para ver la cartera de un asesor o de una zona hay que exportar todo el visualizador a Excel y filtrar a mano.',
    current_process: 'Cada lunes se arma un Excel por zona y se le manda a cada asesor por correo.',
    expected_result: 'Elegir asesor y zona en el visualizador, ver el total al instante y exportar solo eso.',
    hours_per_week: 4,
    people: 5,
    criteria: ['Filtrar por asesor.', 'Filtrar por zona.', 'Combinar ambos filtros.', 'Exportar el resultado a Excel.'],
    product: PRODUCT,
    links: LINKS,
    tasks: [{ key: 'T3', title: 'Endpoint de zonas', status: 'En revisión', github: 'PR #41' }],
    decisions: [{ text: 'SAP B1 se lee solo por la vista de cartera de solo lectura.' }],
};

const TASK: TaskBriefInput = {
    key: 'T4',
    title: 'Consulta de cartera por asesor desde SAP B1',
    project: { key: 'P-07', name: 'Visualizador de cartera · filtros' },
    request: { key: 'GIA-139', title: 'filtro por asesor y zona' },
    status: 'En progreso',
    assignees: ['Jhon Castro'],
    description: 'Traer de SAP B1 la cartera de cada asesor con su zona y los días vencidos. Usa la vista de cartera de solo lectura; nunca escribas en SAP.',
    subtasks: [
        { title: 'Conectar a la vista de cartera de SAP B1 en pruebas', done: true },
        { title: 'Filtrar por código de asesor', done: true },
        { title: 'Agrupar por rango de días vencidos', done: false },
        { title: 'Probar con 3 asesores reales', done: false },
    ],
    criteria: REQUEST.criteria,
    product: PRODUCT,
    links: LINKS,
    pull_request: { number: 41, title: 'GIA-139 endpoint de zonas', state: 'abierto' },
    issue: null,
    suggested_branch: 'gia-139-t4',
};

const PROJECT: ProjectBriefInput = {
    key: 'P-07',
    name: 'Visualizador de cartera · filtros',
    company: 'Segurex',
    area: 'Cartera',
    status: 'En desarrollo',
    phase: 'Construcción',
    request_keys: ['GIA-139'],
    objective: 'Que la Gerencia de cartera filtre la cartera por asesor y por zona sin pedir reportes a TI.',
    criteria: [
        { text: 'Filtrar la cartera por asesor', done: true },
        { text: 'Filtrar la cartera por zona', done: true },
        { text: 'Exportar a Excel lo que se filtró', done: false },
    ],
    product: PRODUCT,
    links: LINKS,
    tasks: [
        { key: 'T2', title: 'Consulta SAP por asesor', status: 'Hecho', github: 'PR #36' },
        { key: 'T4', title: 'Consulta de cartera por asesor', status: 'En progreso', github: null },
    ],
    decisions: [
        { text: 'SAP B1 se lee solo por la vista de cartera de solo lectura.' },
        { text: 'Las zonas salen de la lista que envió Ventas el 5 oct.' },
        { text: 'Edward pidió no mostrarle esto a Ventas todavía.', private: true },
    ],
};

/** Data that must never reach a brief, smuggled in as extra fields. */
const FORBIDDEN = {
    total_cost: 16_000_000,
    external_costs: 2_450_000,
    cost: { estimate: 16_000_000, actual: 14_200_000 },
    rates: [{ person: 'Jhon Castro', hourly_rate: 95_000 }],
    hourly_rate: 95_000,
    internal_notes: [{ body: 'NOTA-INTERNA-XYZ: Paola ya pidió esto dos veces' }],
    vault: { name: 'SAP B1 · usuario de solo lectura', username: 'consulta_cartera', password: 'Cl4v3-S3cr3t4!' },
    credentials: 'ghp_TOKENDEPRUEBA123',
    webhook_secret: 'whsec_SECRETO',
};
const FORBIDDEN_VALUES = [
    '16.000.000',
    '16000000',
    '2450000',
    '14200000',
    '95000',
    '95.000',
    'NOTA-INTERNA-XYZ',
    'consulta_cartera',
    'Cl4v3-S3cr3t4!',
    'ghp_TOKENDEPRUEBA123',
    'whsec_SECRETO',
];

function expectClean(markdown: string) {
    for (const value of FORBIDDEN_VALUES) expect(markdown).not.toContain(value);
    expect(markdown.toLowerCase()).not.toMatch(/contraseña|password|tarifa|costo|bóveda|vault/);
}

describe('BRIEF_SECTIONS', () => {
    it('lists the six sections in the panel order, four on by default', () => {
        expect(BRIEF_SECTIONS.map((section) => section.label)).toEqual([
            'Problema',
            'Criterios de aceptación',
            'Contexto del producto',
            'Enlaces a documentación y repositorio',
            'Tareas del proyecto',
            'Decisiones',
        ]);
        expect(DEFAULT_BRIEF_SECTIONS).toEqual(['problem', 'criteria', 'product', 'links']);
    });
});

describe('buildRequestBrief', () => {
    const md = buildRequestBrief(REQUEST);

    it('opens with the key, the title and one meta line', () => {
        const lines = md.split('\n');
        expect(lines[0]).toBe('# GIA-139 · Visualizador de cartera: filtro por asesor y zona');
        expect(md).toContain('Segurex · Cartera · Gerencia de cartera · Talla L · Proyecto P-07');
    });

    it('has the problem with the time it takes today', () => {
        expect(md).toContain('## Problema');
        expect(md).toContain('Hoy le toma 4 h a la semana a 5 personas de Gerencia de cartera.');
        expect(md).toContain('Cómo se hace hoy: Cada lunes se arma un Excel');
        expect(md).toContain('Resultado esperado: Elegir asesor y zona');
    });

    it('numbers the criteria and lists product context and links', () => {
        expect(md).toContain('## Criterios de aceptación\n\n1. Filtrar por asesor.\n2. Filtrar por zona.');
        expect(md).toContain('## Contexto del producto');
        expect(md).toContain('- Producto: Visualizador de cartera · v3.1.0');
        expect(md).toContain('- Tecnología: Next.js, publicado en Vercel');
        expect(md).toContain('- Datos: SAP Business One vía [FUENTE]');
        expect(md).toContain('## Enlaces');
        expect(md).toContain('- Repositorio: https://github.com/GrupoRyR/visualizador-cartera');
    });

    it('leaves out sections that are off, and adds tasks and decisions only when on', () => {
        expect(md).not.toContain('## Tareas del proyecto');
        expect(md).not.toContain('## Decisiones');
        const only = buildRequestBrief(REQUEST, ['criteria', 'tasks', 'decisions']);
        expect(only).not.toContain('## Problema');
        expect(only).not.toContain('## Enlaces');
        expect(only).toContain('## Tareas del proyecto');
        expect(only).toContain('| T3 Endpoint de zonas | En revisión | PR #41 |');
        expect(only).toContain('## Decisiones\n\n- SAP B1 se lee solo');
    });

    it('skips empty optional data instead of printing placeholders', () => {
        const bare = buildRequestBrief({ ...REQUEST, size: null, project_key: null, hours_per_week: null, people: null, product: null, links: [] });
        expect(bare).toContain('Segurex · Cartera · Gerencia de cartera\n');
        expect(bare).not.toContain('Hoy le toma');
        expect(bare).not.toContain('## Contexto del producto');
        expect(bare).not.toContain('## Enlaces');
    });

    it('ends with exactly one newline', () => {
        expect(md.endsWith('\n')).toBe(true);
        expect(md.endsWith('\n\n')).toBe(false);
    });

    it('never includes costs, rates, vault, credentials or internal notes, even if they are in the input', () => {
        const leaky = { ...REQUEST, ...FORBIDDEN } as RequestBriefInput;
        const all = buildRequestBrief(leaky, BRIEF_SECTIONS.map((section) => section.key));
        expectClean(all);
    });

    it('drops links that point to the vault', () => {
        const withVault = buildRequestBrief({ ...REQUEST, links: [...LINKS, { label: 'Acceso SAP', url: 'https://gestor.example/ai/vault/12' }] });
        expect(withVault).not.toContain('/ai/vault');
        expect(withVault).not.toContain('Acceso SAP');
    });
});

describe('buildTaskBrief', () => {
    const md = buildTaskBrief(TASK);

    it('opens with the task and its project, request and state', () => {
        expect(md.split('\n')[0]).toBe('# T4 · Consulta de cartera por asesor desde SAP B1');
        expect(md).toContain('- Proyecto: P-07 Visualizador de cartera · filtros');
        expect(md).toContain('- Solicitud: GIA-139 · filtro por asesor y zona');
        expect(md).toContain('- Estado: En progreso · Responsable: Jhon Castro');
    });

    it('turns subtasks into a checklist and names the next step', () => {
        expect(md).toContain('## Qué hay que hacer');
        expect(md).toContain('- [x] Conectar a la vista de cartera de SAP B1 en pruebas');
        expect(md).toContain('- [ ] Agrupar por rango de días vencidos');
        expect(md).toContain('## Siguiente paso\n\nAgrupar por rango de días vencidos.');
    });

    it('labels the criteria with the request key and lists PR, issue and branch under links', () => {
        expect(md).toContain('## Criterios de aceptación (GIA-139)');
        expect(md).toContain('- PR relacionado: #41 GIA-139 endpoint de zonas (abierto)');
        expect(md).toContain('- Issue: todavía no tiene · rama sugerida gia-139-t4');
        const withIssue = buildTaskBrief({ ...TASK, issue: { number: 57 } });
        expect(withIssue).toContain('- Issue: #57');
    });

    it('has no next step when every subtask is done', () => {
        const done = buildTaskBrief({ ...TASK, subtasks: TASK.subtasks.map((subtask) => ({ ...subtask, done: true })) });
        expect(done).not.toContain('## Siguiente paso');
    });

    it('never includes forbidden data', () => {
        expectClean(buildTaskBrief({ ...TASK, ...FORBIDDEN } as TaskBriefInput, BRIEF_SECTIONS.map((section) => section.key)));
    });
});

describe('buildProjectBrief', () => {
    const md = buildProjectBrief(PROJECT, BRIEF_SECTIONS.map((section) => section.key));

    it('has the objective, criteria with progress, the task table and decisions', () => {
        expect(md.split('\n')[0]).toBe('# P-07 · Visualizador de cartera · filtros');
        expect(md).toContain('Segurex · Cartera · En desarrollo, fase Construcción · nace de GIA-139');
        expect(md).toContain('## Objetivo\n\nQue la Gerencia de cartera');
        expect(md).toContain('## Criterios de aceptación · 2 de 3 cumplidos');
        expect(md).toContain('- [x] Filtrar la cartera por asesor');
        expect(md).toContain('- [ ] Exportar a Excel lo que se filtró');
        expect(md).toContain('| Tarea | Estado | GitHub |');
        expect(md).toContain('| T4 Consulta de cartera por asesor | En progreso | sin issue |');
    });

    it('omits private decisions', () => {
        expect(md).toContain('- Las zonas salen de la lista que envió Ventas el 5 oct.');
        expect(md).not.toContain('Edward pidió');
    });

    it('escapes pipes inside table cells', () => {
        const piped = buildProjectBrief({ ...PROJECT, tasks: [{ key: 'T1', title: 'A | B', status: 'Hecho', github: null }] }, ['tasks']);
        expect(piped).toContain('| T1 A \\| B | Hecho | sin issue |');
    });

    it('never includes forbidden data', () => {
        expectClean(buildProjectBrief({ ...PROJECT, ...FORBIDDEN } as ProjectBriefInput, BRIEF_SECTIONS.map((section) => section.key)));
    });
});

describe('buildClaudeMd', () => {
    it('fills the template with placeholders when data is missing', () => {
        const md = buildClaudeMd({});
        expect(md.split('\n')[0]).toBe('# CLAUDE.md · [NOMBRE DEL PROYECTO]');
        expect(md).toContain('## Objetivo\n\n[OBJETIVO, tomado de la solicitud aprobada]');
        expect(md).toContain('- [STACK] · se publica en [HOSTING]');
        expect(md).toContain('- Datos: [FUENTE DE DATOS], solo lectura');
        expect(md).toContain('- `npm run dev` corre en local');
        expect(md).toContain('## Nunca subir secretos');
        expect(md).toContain('- Contraseñas y accesos viven en la bóveda, no aquí');
    });

    it('uses the project data and custom commands', () => {
        const md = buildClaudeMd({
            project_name: 'Visualizador de cartera',
            objective: 'Filtrar la cartera por asesor y zona.',
            stack: 'Next.js',
            host: 'Vercel',
            data_source: 'SAP Business One',
            commands: [{ command: 'pnpm dev', description: 'corre en local' }],
        });
        expect(md).toContain('# CLAUDE.md · Visualizador de cartera');
        expect(md).toContain('- Next.js · se publica en Vercel');
        expect(md).toContain('- `pnpm dev` corre en local');
        expect(md).not.toContain('npm install');
    });

    it('never copies secrets from the input', () => {
        const md = buildClaudeMd({ project_name: 'X', ...FORBIDDEN } as Parameters<typeof buildClaudeMd>[0]);
        for (const value of FORBIDDEN_VALUES) expect(md).not.toContain(value);
    });
});

describe('briefStats', () => {
    it('counts words without Markdown markers and lines without the final newline', () => {
        expect(briefStats('# Hola mundo\n\n- [x] uno dos\n1. tres\n')).toEqual({ words: 5, lines: 4 });
    });

    it('counts keys and numbers as words and ignores separators', () => {
        expect(briefStats('GIA-139 · Talla L · 4 h')).toEqual({ words: 5, lines: 1 });
    });

    it('is zero for an empty brief', () => {
        expect(briefStats('')).toEqual({ words: 0, lines: 0 });
    });
});

describe('briefFileName', () => {
    it('joins the keys and ends in -brief.md', () => {
        expect(briefFileName('GIA-139')).toBe('GIA-139-brief.md');
        expect(briefFileName('P-07', 'T4')).toBe('P-07-T4-brief.md');
    });
});

describe('withTaskGitHub', () => {
    const base: TaskBriefInput = { ...TASK, pull_request: null, issue: null, suggested_branch: null };

    it('adds the suggested branch from the request key when the task has no issue', () => {
        const merged = withTaskGitHub(base, { request_key: 'GIA-139', issue: null, pull_request: null });
        expect(merged.suggested_branch).toBe('gia-139-t4');
        expect(buildTaskBrief(merged)).toContain('- Issue: todavía no tiene · rama sugerida gia-139-t4');
    });

    it('uses the task issue and its own PR, with the state in Spanish', () => {
        const merged = withTaskGitHub(base, {
            request_key: 'GIA-139',
            issue: { number: 57 },
            pull_request: { number: 41, title: 'GIA-139 endpoint de zonas', state: 'merged' },
        });
        expect(merged.issue).toEqual({ number: 57 });
        expect(merged.pull_request).toEqual({ number: 41, title: 'GIA-139 endpoint de zonas', state: 'fusionado' });
    });

    it('keeps the related PR the server sent when the task has none, and the server branch', () => {
        const related = { number: 41, title: 'GIA-139 endpoint de zonas', state: 'abierto' };
        const merged = withTaskGitHub({ ...base, pull_request: related, suggested_branch: 'gia-139-t4-cartera' }, { request_key: 'GIA-139', issue: null, pull_request: null });
        expect(merged.pull_request).toEqual(related);
        expect(merged.suggested_branch).toBe('gia-139-t4-cartera');
    });

    it('returns the input unchanged without GitHub data', () => {
        expect(withTaskGitHub(base, null)).toBe(base);
    });
});

describe('omittedNote', () => {
    it('names what the brief left out', () => {
        expect(omittedNote({ time_and_costs: true, internal_notes: 2 })).toBe('Aquí se omitieron el tiempo y costos y 2 notas internas.');
        expect(omittedNote({ time_and_costs: false, internal_notes: 1 })).toBe('Aquí se omitió 1 nota interna.');
        expect(omittedNote({ time_and_costs: true, internal_notes: 0 })).toBe('Aquí se omitieron el tiempo y costos.');
    });

    it('is null when nothing was left out', () => {
        expect(omittedNote({ time_and_costs: false, internal_notes: 0 })).toBeNull();
        expect(omittedNote(null)).toBeNull();
    });
});

describe('claudeMdInputFromProject', () => {
    it('takes name, objective and product context from the project brief', () => {
        expect(claudeMdInputFromProject(PROJECT)).toEqual({
            project_name: 'Visualizador de cartera · filtros',
            objective: PROJECT.objective,
            stack: 'Next.js',
            host: 'Vercel',
            data_source: 'SAP Business One vía [FUENTE]',
        });
    });

    it('leaves placeholders when the project has no product yet', () => {
        const md = buildClaudeMd(claudeMdInputFromProject({ ...PROJECT, product: null }));
        expect(md).toContain('- [STACK] · se publica en [HOSTING]');
    });

    it('never carries forbidden data into the CLAUDE.md', () => {
        const md = buildClaudeMd(claudeMdInputFromProject({ ...PROJECT, ...FORBIDDEN } as ProjectBriefInput));
        for (const value of FORBIDDEN_VALUES) expect(md).not.toContain(value);
    });
});

describe('repoNameError', () => {
    it('accepts GitHub repository names', () => {
        expect(repoNameError('toma-pedidos-descuentos')).toBeNull();
        expect(repoNameError('visualizador_cartera.v2')).toBeNull();
    });

    it('explains what is wrong', () => {
        expect(repoNameError('  ')).toBe('Escribe el nombre del repositorio.');
        expect(repoNameError('toma pedidos')).toBe('Usa solo letras sin tilde, números, guiones, puntos o guion bajo.');
        expect(repoNameError('a'.repeat(101))).toBe('Máximo 100 caracteres.');
        expect(repoNameError('..')).toBe('Ese nombre no lo acepta GitHub.');
    });
});
