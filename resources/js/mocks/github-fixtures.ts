/**
 * Single source of Stage D mock entities (GitHub, releases, AI suggestions),
 * taken from the approved boards ("Etapa D · Conexiones", 2026-10-09) and
 * reconciled with Stage B: T2 merged in PR #36, PR #41 (T3) is by Leonardo
 * Herrera, Visualizador v3.1.0 shipped on 22 sep, and T4 has no issue until
 * the create-issue flow makes #57 (`ISSUE_57` is that post-create state).
 *
 * Also the whitelisted inputs of "Brief para Claude" (`BRIEF_*`): what the
 * server would send to `ai.manage`, without costs, vault or internal notes.
 *
 * Today in the mocks is miércoles 7 oct 2026, 9:40 a. m. (`NOW_ISO`). This
 * file exports no `variants`, so the gallery ignores it.
 */
import type {
    CiRun,
    CommitSummary,
    Deploy,
    GitHubActor,
    GitHubConnection,
    GitHubIssue,
    GitHubRepo,
    GitHubRepoLink,
    PlannedRelease,
    PullRequest,
    Release,
    ReleaseSummary,
    WebhookEvent,
    WebhookStats,
} from '@/types/github';
import type { BriefLink, BriefProduct, BriefTaskRow, ProjectBriefInput, RequestBriefInput, TaskBriefInput } from '@/lib/brief';
import type { AiUsage, Suggestion } from '@/types/suggestions';
import { PEOPLE } from './ai-fixtures';

export const GITHUB_ORG = 'GrupoRyR';
const GH = 'https://github.com';
const repoUrl = (name: string) => `${GH}/${GITHUB_ORG}/${name}`;

// ---- actors ---------------------------------------------------------------

const GH_LOGIN = '[USUARIO DE GITHUB]';

export const GH_ACTORS = {
    jhon: { name: PEOPLE.jhon.name, login: GH_LOGIN },
    leonardo: { name: PEOPLE.leonardo.name, login: GH_LOGIN },
} satisfies Record<string, GitHubActor>;

// ---- connection -----------------------------------------------------------

export const GITHUB_CONNECTION: GitHubConnection = {
    connected: true,
    kind: 'app',
    app_name: 'Gestor TI RYR',
    organization: GITHUB_ORG,
    installed_repos_count: 6,
    permissions: [
        { scope: 'issues', access: 'write' },
        { scope: 'pull_requests', access: 'read' },
        { scope: 'contents', access: 'read' },
        { scope: 'releases', access: 'read' },
        { scope: 'actions', access: 'read' },
    ],
    private_key_configured: true,
    manage_url: `${GH}/settings/installations`,
    webhook: {
        url: 'https://[DOMINIO DEL GESTOR]/api/github/webhook',
        configured: true,
        events: ['issues', 'pull_request', 'release', 'workflow_run', 'push'],
        last_delivery_at: '2026-10-07T09:36:00-05:00',
    },
};

export const WEBHOOK_STATS: WebhookStats = { month: 'octubre', received: 214, processed: 212, failed: 2 };

export const FAILED_WEBHOOK_EVENTS: WebhookEvent[] = [
    {
        id: 2141,
        type: 'workflow_run',
        repo: `${GITHUB_ORG}/visualizador-cartera`,
        received_at: '2026-10-07T06:12:00-05:00',
        status: 'failed',
        error: 'Se agotó el tiempo al procesarlo.',
        error_code: 'timeout',
    },
    {
        id: 2098,
        type: 'release',
        repo: `${GITHUB_ORG}/robotcamara`,
        received_at: '2026-10-05T17:40:00-05:00',
        status: 'failed',
        error: 'No está vinculado a ningún producto.',
        error_code: 'repo_not_linked',
    },
];

// ---- repositories ---------------------------------------------------------

const repo = (seed: Omit<GitHubRepo, 'full_name' | 'url' | 'private' | 'default_branch' | 'branches'> & Partial<Pick<GitHubRepo, 'branches'>>): GitHubRepo => ({
    full_name: `${GITHUB_ORG}/${seed.name}`,
    url: repoUrl(seed.name),
    private: true,
    default_branch: 'main',
    branches: ['main'],
    ...seed,
});

/** The 6 repositories the app is installed on; robotcamara is not linked yet. */
export const REPOS: GitHubRepo[] = [
    repo({ name: 'robotcamara', language: 'Python', pushed_at: '2026-10-05T11:00:00-05:00', linked_to: null, branches: ['main', 'develop'] }),
    repo({ name: 'gestor-ti', language: 'PHP', pushed_at: '2026-10-07T08:40:00-05:00', linked_to: { kind: 'product', key: 'gestor-ti', name: 'Gestor TI' } }),
    repo({
        name: 'visualizador-cartera',
        language: 'TypeScript',
        pushed_at: '2026-10-07T06:38:00-05:00',
        linked_to: { kind: 'product', key: 'visualizador-cartera', name: 'Visualizador de cartera' },
        branches: ['main', 'gia-139-endpoint-zonas'],
    }),
    repo({ name: 'toma-pedidos', language: 'PHP', pushed_at: '2026-10-03T16:00:00-05:00', linked_to: { kind: 'product', key: 'toma-pedidos', name: 'Toma Pedidos SAP' } }),
    repo({
        name: 'cuentas-por-pagar',
        language: 'TypeScript',
        pushed_at: '2026-09-28T10:00:00-05:00',
        linked_to: { kind: 'product', key: 'cuentas-por-pagar', name: 'Cuentas por pagar' },
    }),
    repo({ name: 'portafolio', language: 'PHP', pushed_at: '2026-10-02T15:50:00-05:00', linked_to: { kind: 'product', key: 'portafolio', name: 'Portafolio de productos' } }),
];

export const repoByName = (name: string): GitHubRepo => {
    const found = REPOS.find((item) => item.name === name);
    if (!found) throw new Error(`Unknown repo ${name}`);
    return found;
};

export const REPO_VISUALIZADOR = repoByName('visualizador-cartera');
export const REPO_PORTAFOLIO = repoByName('portafolio');
export const REPO_ROBOTCAMARA = repoByName('robotcamara');

export const LINK_VISUALIZADOR: GitHubRepoLink = {
    repo: REPO_VISUALIZADOR,
    default_branch: 'main',
    read_docs: true,
    receive_events: true,
    linked_at: '2026-08-14T10:00:00-05:00',
    linked_by: PEOPLE.jhon.name,
};

/** Draft state of the "Vincular repositorio" dialog for Cámaras. */
export const LINK_DRAFT_ROBOTCAMARA = { repo: REPO_ROBOTCAMARA.full_name, default_branch: 'main', read_docs: true, receive_events: true };

// ---- pull requests --------------------------------------------------------

const P07_T = (task_key: string, task_title: string) => ({ request_key: 'GIA-139', project_key: 'P-07', task_key, task_title });

export const PR_41: PullRequest = {
    number: 41,
    title: 'GIA-139 endpoint de zonas por asesor',
    state: 'open',
    ci: 'passing',
    commits: 2,
    author: GH_ACTORS.leonardo,
    branch: 'gia-139-endpoint-zonas',
    base_branch: 'main',
    linked: P07_T('T3', 'Endpoint de zonas'),
    url: `${repoUrl('visualizador-cartera')}/pull/41`,
    opened_at: '2026-10-06T17:12:00-05:00',
    merged_at: null,
    closed_at: null,
};

export const PR_36: PullRequest = {
    number: 36,
    title: 'GIA-139 consulta SAP por asesor',
    state: 'merged',
    ci: 'passing',
    commits: 5,
    author: GH_ACTORS.jhon,
    branch: 'gia-139-consulta-sap',
    base_branch: 'main',
    linked: P07_T('T2', 'Consulta SAP por asesor'),
    url: `${repoUrl('visualizador-cartera')}/pull/36`,
    opened_at: '2026-10-02T11:00:00-05:00',
    merged_at: '2026-10-07T06:38:00-05:00',
    closed_at: '2026-10-07T06:38:00-05:00',
};

/** Closed without merging; its CI failed on 3 oct. */
export const PR_40: PullRequest = {
    number: 40,
    title: 'GIA-139 zonas desde la tabla de asesores',
    state: 'closed',
    ci: 'failing',
    commits: 3,
    author: GH_ACTORS.leonardo,
    branch: 'gia-139-zonas-tabla',
    base_branch: 'main',
    linked: P07_T('T3', 'Endpoint de zonas'),
    url: `${repoUrl('visualizador-cartera')}/pull/40`,
    opened_at: '2026-10-02T15:00:00-05:00',
    merged_at: null,
    closed_at: '2026-10-03T12:00:00-05:00',
};

export const PULL_REQUESTS_VISUALIZADOR: PullRequest[] = [PR_41, PR_40, PR_36];

// ---- issues ---------------------------------------------------------------

/** Created from T4 with "Crear issue en GitHub" (post-create state). */
export const ISSUE_57: GitHubIssue = {
    number: 57,
    title: 'GIA-139 · T4 Consulta de cartera por asesor desde SAP B1',
    state: 'open',
    labels: ['gestor-ia', 'sap', 'backend'],
    assignee: GH_ACTORS.jhon,
    created_from_gestor: true,
    created_by: GH_ACTORS.jhon,
    created_at: '2026-10-07T09:38:00-05:00',
    closed_at: null,
    closed_by_pr: null,
    linked: P07_T('T4', 'Consulta de cartera por asesor desde SAP B1'),
    url: `${repoUrl('visualizador-cartera')}/issues/57`,
};

export const ISSUE_39: GitHubIssue = {
    number: 39,
    title: 'GIA-139 · T5 Pruebas con cartera real',
    state: 'open',
    labels: ['gestor-ia', 'pruebas'],
    assignee: GH_ACTORS.jhon,
    created_from_gestor: true,
    created_by: GH_ACTORS.jhon,
    created_at: '2026-10-02T10:00:00-05:00',
    closed_at: null,
    closed_by_pr: null,
    linked: P07_T('T5', 'Pruebas con cartera real'),
    url: `${repoUrl('visualizador-cartera')}/issues/39`,
};

export const ISSUE_35: GitHubIssue = {
    number: 35,
    title: 'GIA-112 · Totales por rango de días vencidos',
    state: 'closed',
    labels: ['gestor-ia'],
    assignee: GH_ACTORS.leonardo,
    created_from_gestor: true,
    created_by: GH_ACTORS.jhon,
    created_at: '2026-09-08T09:00:00-05:00',
    closed_at: '2026-09-20T16:00:00-05:00',
    closed_by_pr: 34,
    linked: { request_key: 'GIA-112', project_key: null, task_key: null, task_title: null },
    url: `${repoUrl('visualizador-cartera')}/issues/35`,
};

/** Recent issues after #57 is created. Before creating, drop `ISSUE_57`. */
export const ISSUES_VISUALIZADOR: GitHubIssue[] = [ISSUE_57, ISSUE_39, ISSUE_35];

// ---- CI, deploy, commits --------------------------------------------------

export const CI_MAIN_VISUALIZADOR: CiRun = {
    status: 'passing',
    branch: 'main',
    commit_sha: 'a1b2c3d',
    run_at: '2026-10-07T06:40:00-05:00',
    summary: null,
    url: `${repoUrl('visualizador-cartera')}/actions`,
};

export const CI_PR_41: CiRun = {
    status: 'passing',
    branch: 'gia-139-endpoint-zonas',
    commit_sha: '4d5e6f7',
    run_at: '2026-10-06T17:18:00-05:00',
    summary: '2 de 2 pruebas automáticas',
    url: `${repoUrl('visualizador-cartera')}/pull/41/checks`,
};

/** Last deploy "hace 3 h". */
export const DEPLOY_VISUALIZADOR: Deploy = {
    provider: 'Vercel',
    branch: 'main',
    commit_sha: 'a1b2c3d',
    deployed_at: '2026-10-07T06:42:00-05:00',
    trigger: 'push',
    url: '[URL DEL VISUALIZADOR]',
};

const commit = (sha: string, message: string, author: GitHubActor, committed_at: string): CommitSummary => ({
    sha,
    message,
    author,
    committed_at,
    url: `${repoUrl('visualizador-cartera')}/commit/${sha}`,
});

/** 5 of the 11 commits on main in the last 7 days (jue 1 a mié 7 oct, 2 personas). */
export const COMMITS_VISUALIZADOR: CommitSummary[] = [
    commit('a1b2c3d', 'GIA-139 consulta SAP por asesor (#36)', GH_ACTORS.jhon, '2026-10-07T06:38:00-05:00'),
    commit('9f8e7d6', 'Ajustar caché de la vista de cartera', GH_ACTORS.leonardo, '2026-10-06T15:20:00-05:00'),
    commit('5c4b3a2', 'GIA-139 tipos de asesor y zona', GH_ACTORS.jhon, '2026-10-06T10:05:00-05:00'),
    commit('3e2d1c0', 'Actualizar dependencias de Next.js', GH_ACTORS.jhon, '2026-10-05T16:30:00-05:00'),
    commit('7a6b5c4', 'docs: cómo correr las pruebas de la consulta SAP', GH_ACTORS.leonardo, '2026-10-01T11:00:00-05:00'),
];
export const COMMITS_VISUALIZADOR_TOTAL = 11;

// ---- releases -------------------------------------------------------------

export const RELEASES_VISUALIZADOR: ReleaseSummary[] = [
    {
        tag: 'v3.1.0',
        summary: 'Totales por edad de cartera y carga más rápida',
        published_at: '2026-09-22T16:00:00-05:00',
        request_keys: ['GIA-112', 'GIA-115'],
        notified_count: 2,
        url: `${repoUrl('visualizador-cartera')}/releases/tag/v3.1.0`,
    },
    {
        tag: 'v3.0.0',
        summary: 'Paso a Next.js en Vercel y entrada con Microsoft',
        published_at: '2026-08-18T16:00:00-05:00',
        request_keys: ['GIA-095', 'GIA-099'],
        notified_count: 2,
        url: `${repoUrl('visualizador-cartera')}/releases/tag/v3.0.0`,
    },
    {
        tag: 'v2.6.1',
        summary: 'Cliente duplicado en el listado de cartera',
        published_at: '2026-07-10T16:00:00-05:00',
        request_keys: ['GIA-088'],
        notified_count: 1,
        url: `${repoUrl('visualizador-cartera')}/releases/tag/v2.6.1`,
    },
];

export const PLANNED_RELEASE_VISUALIZADOR: PlannedRelease = {
    tag: 'v3.2.0',
    summary: 'con GIA-139 filtro por asesor y zona',
    request_keys: ['GIA-139'],
};

const PORTAFOLIO_NOTES = `## Cambios
- feat: buscador por referencia con coincidencia parcial (GIA-118) #22
- perf: índice por referencia en la tabla de productos #23
- fix: escapar comillas en la búsqueda #23
- chore: actualizar dependencias de PHP #24

## Colaboradores
@[USUARIO DE GITHUB]
`;

/** Portafolio v1.8.2 on its release page, before Jhon confirms the user notes. */
export const RELEASE_PORTAFOLIO_182: Release = {
    tag: 'v1.8.2',
    summary: 'Buscador por referencia',
    published_at: '2026-10-02T15:58:00-05:00',
    request_keys: ['GIA-118'],
    notified_count: 0,
    url: `${repoUrl('portafolio')}/releases/tag/v1.8.2`,
    repo: `${GITHUB_ORG}/portafolio`,
    author: GH_ACTORS.jhon,
    included: [
        {
            key: 'GIA-118',
            title: 'Buscador por referencia',
            status: 'in_production',
            requester: { name: PEOPLE.andres.name, company: 'Derivados', area: 'Compras' },
            found_in_pr: 22,
        },
    ],
    technical_notes: PORTAFOLIO_NOTES,
    user_notes: null,
    version_created: true,
    notified_at: null,
    compare: { label: 'v1.8.1...v1.8.2', url: `${repoUrl('portafolio')}/compare/v1.8.1...v1.8.2` },
};

export const RELEASE_USER_NOTES_182 =
    'Ahora puedes encontrar un producto escribiendo su referencia en el buscador, aunque escribas solo una parte. Ya no hace falta recorrer las categorías una por una.\n\nLos resultados aparecen mientras escribes.';

/** Same release after "Avisar a 1 solicitante" (vie 2 oct, 4:12 p. m.). */
export const RELEASE_PORTAFOLIO_182_NOTIFIED: Release = {
    ...RELEASE_PORTAFOLIO_182,
    user_notes: RELEASE_USER_NOTES_182,
    notified_count: 1,
    notified_at: '2026-10-02T16:12:00-05:00',
};

// ---- AI suggestions -------------------------------------------------------

const PROVIDER = 'DeepSeek';
/** "hace 2 min" at NOW_ISO. */
const SUGGESTED_AT = '2026-10-07T09:38:00-05:00';

const suggestion = <K extends Suggestion['kind']>(seed: Pick<Suggestion<K>, 'id' | 'kind' | 'payload'> & Partial<Suggestion<K>>): Suggestion<K> => ({
    state: 'ready',
    provider: PROVIDER,
    created_at: SUGGESTED_AT,
    accepted: null,
    discarded: null,
    stale: null,
    error: null,
    ...seed,
});

const SUMMARY_GIA_148 =
    'Paola revisa en SAP dos veces al día los pedidos bloqueados por cartera y avisa a cada asesor por WhatsApp. Pide un aviso automático en Toma Pedidos y por correo, con el saldo vencido del cliente.';

/** GIA-148 summary, already used by Jhon (shows the "Basado en una sugerencia" trace). */
export const SUGGESTION_SUMMARY_GIA_148: Suggestion<'summary'> = suggestion({
    id: 9001,
    kind: 'summary',
    state: 'accepted',
    payload: { text: SUMMARY_GIA_148 },
    accepted: { by: PEOPLE.jhon.name, at: '2026-10-07T09:39:00-05:00' },
});

/** Same summary still waiting for Jhon (phone board). */
export const SUGGESTION_SUMMARY_GIA_148_READY: Suggestion<'summary'> = { ...SUGGESTION_SUMMARY_GIA_148, state: 'ready', accepted: null };

export const SUGGESTION_DUPLICATE_GIA_148: Suggestion<'duplicate'> = suggestion({
    id: 9002,
    kind: 'duplicate',
    payload: {
        request: { key: 'GIA-121', title: 'Alerta de pedidos bloqueados por cartera', status: 'backlog', people: 4 },
        level: 'alto',
        reason_summary: 'mismo problema y misma app',
        reasons: [
            'Mismo problema: el asesor no se entera del bloqueo por cartera.',
            'Misma app: Toma Pedidos SAP.',
            'Las dos piden avisar dentro de Toma Pedidos.',
        ],
        differences: ['GIA-148 pide además el aviso por correo.', 'GIA-148 pide mostrar el saldo vencido del cliente.'],
    },
});

/** An older duplicate suggestion Jhon already discarded (collapsed row). */
export const SUGGESTION_DUPLICATE_GIA_148_DISCARDED: Suggestion<'duplicate'> = suggestion({
    id: 8990,
    kind: 'duplicate',
    state: 'discarded',
    created_at: '2026-10-07T09:05:00-05:00',
    payload: {
        request: { key: 'GIA-117', title: 'Bloqueos de pedidos en SAP', status: 'closed', people: 2 },
        level: 'medio',
        reason_summary: 'misma app',
        reasons: ['Misma app: Toma Pedidos SAP.'],
        differences: [],
    },
    discarded: { by: PEOPLE.jhon.name, at: '2026-10-07T09:10:00-05:00', reason: null },
});

export const SUGGESTION_CRITERIA_GIA_148: Suggestion<'criteria'> = suggestion({
    id: 9003,
    kind: 'criteria',
    payload: {
        items: [
            { id: 1, text: 'El asesor ve un aviso en Toma Pedidos apenas SAP bloquea su pedido.' },
            { id: 2, text: 'El aviso llega también a su correo con cliente, pedido y saldo vencido.' },
            { id: 3, text: 'Cuando el pedido se desbloquea, el aviso se marca como resuelto.' },
        ],
    },
});

export const SUGGESTION_SIZE_GIA_148: Suggestion<'size'> = suggestion({
    id: 9004,
    kind: 'size',
    payload: { size: 'M', reason: 'El bloqueo ya se lee con la consulta del Visualizador de cartera; el trabajo es el aviso y el correo.' },
});

export const SUGGESTIONS_GIA_148 = {
    summary: SUGGESTION_SUMMARY_GIA_148,
    duplicate: SUGGESTION_DUPLICATE_GIA_148,
    duplicate_discarded: SUGGESTION_DUPLICATE_GIA_148_DISCARDED,
    criteria: SUGGESTION_CRITERIA_GIA_148,
    size: SUGGESTION_SIZE_GIA_148,
};

/** States board ("Sugerencias de IA · estados"). */
export const SUGGESTION_GENERATING: Suggestion<'summary'> = suggestion({ id: 9010, kind: 'summary', state: 'generating', payload: null, created_at: '2026-10-07T09:40:00-05:00' });

export const SUGGESTION_ERROR: Suggestion<'summary'> = suggestion({
    id: 9011,
    kind: 'summary',
    state: 'error',
    payload: null,
    error: { message: 'No se pudo generar la sugerencia.', detail: 'DeepSeek no respondió a tiempo. La solicitud no cambió.', code: 'ai_timeout', at: '2026-10-07T09:35:00-05:00' },
});

export const SUGGESTION_TOO_SHORT: Suggestion<'summary'> = suggestion({
    id: 9012,
    kind: 'summary',
    state: 'error',
    payload: null,
    error: {
        message: 'No hay suficiente texto para resumir.',
        detail: 'GIA-150 tiene menos de [MÍNIMO DE PALABRAS] palabras. Pídele más detalle a quien la envió.',
        code: 'too_short',
        at: '2026-10-07T09:30:00-05:00',
    },
});

export const SUGGESTION_STALE: Suggestion<'summary'> = suggestion({
    id: 9013,
    kind: 'summary',
    state: 'stale',
    created_at: '2026-10-07T08:40:00-05:00',
    payload: { text: SUMMARY_GIA_148 },
    stale: { reason: 'Paola editó el resultado esperado', at: '2026-10-07T09:20:00-05:00' },
});

/** Draft of user-facing notes for Portafolio v1.8.2. */
export const SUGGESTION_RELEASE_NOTES_182: Suggestion<'release_notes'> = suggestion({
    id: 9020,
    kind: 'release_notes',
    created_at: '2026-10-02T16:00:00-05:00',
    payload: {
        title: 'Buscar por referencia',
        body: RELEASE_USER_NOTES_182,
        source_note: 'Hecha con el título y los criterios de GIA-118. No usa costos ni notas internas.',
    },
});

export const AI_USAGE_CAP: AiUsage = { spent_label: '[GASTO DEL MES]', cap_label: '[TOPE MENSUAL]', resumes_on: '1 de noviembre' };

// ---- Brief para Claude (whitelisted inputs, D-Brief*) ---------------------

export const BRIEF_PRODUCT_VISUALIZADOR: BriefProduct = {
    name: 'Visualizador de cartera',
    version: 'v3.1.0',
    stack: 'Next.js',
    host: 'Vercel',
    data_source: 'SAP Business One vía [FUENTE]',
};

/** Repo, CLAUDE.md and the technical manual; the backend drops any vault link. */
export const BRIEF_LINKS_VISUALIZADOR: BriefLink[] = [
    { label: 'Repositorio', url: repoUrl('visualizador-cartera') },
    { label: 'Instrucciones para Claude (CLAUDE.md)', url: `${repoUrl('visualizador-cartera')}/blob/main/CLAUDE.md` },
    { label: 'Manual técnico y de despliegue', url: 'https://[DOMINIO DEL GESTOR]/ai/documentacion/5' },
];

const GIA_139_CRITERIA = ['Filtrar por asesor.', 'Filtrar por zona.', 'Combinar ambos filtros.', 'Exportar el resultado a Excel.'];

/** P-07 rows of the brief task table: key, column label and the GitHub reference. */
export const BRIEF_TASKS_P07: BriefTaskRow[] = [
    { key: 'T2', title: 'Consulta SAP por asesor', status: 'Hecho', github: `PR #${PR_36.number}` },
    { key: 'T3', title: 'Endpoint de zonas', status: 'En revisión', github: `PR #${PR_41.number}` },
    { key: 'T4', title: 'Consulta de cartera por asesor', status: 'En progreso', github: null },
    { key: 'T7', title: 'Total vencido por rango de días', status: 'Bloqueada', github: null },
];

export const BRIEF_REQUEST_GIA_139: RequestBriefInput = {
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
    criteria: GIA_139_CRITERIA,
    product: BRIEF_PRODUCT_VISUALIZADOR,
    links: BRIEF_LINKS_VISUALIZADOR,
    tasks: BRIEF_TASKS_P07,
    decisions: [{ text: 'SAP B1 se lee solo por la vista de cartera de solo lectura.' }],
};

/** T4 (no issue yet): the server sends PR #41 as the related PR of GIA-139; the page adds the branch. */
export const BRIEF_TASK_T4: TaskBriefInput = {
    key: 'T4',
    title: 'Consulta de cartera por asesor desde SAP B1',
    project: { key: 'P-07', name: 'Visualizador de cartera · filtros' },
    request: { key: 'GIA-139', title: 'filtro por asesor y zona' },
    status: 'En progreso',
    assignees: [PEOPLE.jhon.name],
    description: 'Traer de SAP B1 la cartera de cada asesor con su zona y los días vencidos. Usa la vista de cartera de solo lectura; nunca escribas en SAP.',
    subtasks: [
        { title: 'Conectar a la vista de cartera de SAP B1 en pruebas', done: true },
        { title: 'Filtrar por código de asesor', done: true },
        { title: 'Agrupar por rango de días vencidos', done: false },
        { title: 'Probar con 3 asesores reales', done: false },
        { title: 'Documentar la consulta', done: false },
    ],
    criteria: GIA_139_CRITERIA,
    product: BRIEF_PRODUCT_VISUALIZADOR,
    links: BRIEF_LINKS_VISUALIZADOR.slice(0, 2),
    pull_request: { number: PR_41.number, title: PR_41.title, state: 'abierto' },
    issue: null,
    tasks: BRIEF_TASKS_P07,
    decisions: [{ text: 'SAP B1 se lee solo por la vista de cartera de solo lectura.' }],
};

export const BRIEF_PROJECT_P07: ProjectBriefInput = {
    key: 'P-07',
    name: 'Visualizador de cartera · filtros',
    company: 'Segurex',
    area: 'Cartera',
    status: 'En desarrollo',
    phase: 'Construcción',
    request_keys: ['GIA-139'],
    objective: 'Que la Gerencia de cartera filtre la cartera por asesor y por zona sin pedir reportes a TI, y vea cuánto tiene vencido cada asesor.',
    criteria: [
        { text: 'Filtrar la cartera por asesor', done: true },
        { text: 'Filtrar la cartera por zona', done: true },
        { text: 'Ver el total vencido por asesor y por rango de días', done: false },
        { text: 'Exportar a Excel lo que se filtró', done: false },
        { text: 'Cargar en menos de 3 segundos con toda la cartera', done: false },
    ],
    product: BRIEF_PRODUCT_VISUALIZADOR,
    links: BRIEF_LINKS_VISUALIZADOR,
    tasks: BRIEF_TASKS_P07,
    decisions: [
        { text: 'SAP B1 se lee solo por la vista de cartera de solo lectura.' },
        { text: 'Las zonas salen de la lista que envió Ventas el 5 oct.' },
    ],
};

/** P-06 without a repository yet: the CLAUDE.md tab offers "Crear repositorio". */
export const BRIEF_PROJECT_P06: ProjectBriefInput = {
    key: 'P-06',
    name: 'Toma Pedidos · descuentos por volumen',
    company: 'Segurex',
    area: 'Ventas',
    status: 'En pruebas',
    phase: 'Construcción',
    request_keys: ['GIA-127'],
    objective: 'Que Toma Pedidos aplique solo los descuentos por volumen aprobados por Ventas.',
    criteria: [],
    product: { name: 'Toma Pedidos SAP', version: 'v2.4.0', stack: 'Laravel', host: 'GoDaddy', data_source: 'SAP Business One' },
    links: [],
    tasks: [],
    decisions: [],
};
