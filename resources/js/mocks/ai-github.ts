/**
 * Mocks for Stage D · GitHub: the release page (`Ai/Releases/Show`) and the
 * GitHub pieces other pages add (product activity, repo link dialog, task
 * issue/PR blocks). Entities come from github-fixtures and ai-fixtures;
 * ai-knowledge and ai-projects import the pieces from here.
 */
import type { GitHubIssue } from '@/types/github';
import type { IssueFormSetup, ProductGitHub, ReleasesShowProps, RepoLinkSetup, TaskGitHub } from '@/types/pages/ai-github';
import type { PageProps, Permission, SharedProps } from '@/types/shared';
import { CRITERIA_P07, NOW_ISO, PEOPLE, productBySlug } from './ai-fixtures';
import {
    AI_USAGE_CAP,
    CI_MAIN_VISUALIZADOR,
    CI_PR_41,
    COMMITS_VISUALIZADOR,
    COMMITS_VISUALIZADOR_TOTAL,
    DEPLOY_VISUALIZADOR,
    GITHUB_ORG,
    ISSUE_35,
    ISSUE_39,
    ISSUE_57,
    LINK_DRAFT_ROBOTCAMARA,
    LINK_VISUALIZADOR,
    PLANNED_RELEASE_VISUALIZADOR,
    PR_40,
    PR_41,
    RELEASE_PORTAFOLIO_182,
    RELEASE_PORTAFOLIO_182_NOTIFIED,
    RELEASE_USER_NOTES_182,
    RELEASES_VISUALIZADOR,
    REPOS,
    SUGGESTION_RELEASE_NOTES_182,
} from './github-fixtures';
import { sharedFor, type MockUserKey, type MockVariant } from './shared';

/** Absolute links the server would send (the preview has no Ziggy). */
const GESTOR_URL = 'https://[DOMINIO DEL GESTOR]';

function without(key: MockUserKey, ...permissions: Permission[]): SharedProps {
    const removed: string[] = permissions;
    const shared = sharedFor(key);
    const user = shared.auth.user;
    return { ...shared, auth: { user: { ...user, permissions: user.permissions.filter((permission) => !removed.includes(permission)) } } };
}

// ---- product activity -------------------------------------------------------

/** D-ProductGitHub: Visualizador de cartera, data read "hace 2 min". */
export const PRODUCT_GITHUB_VISUALIZADOR: ProductGitHub = {
    link: LINK_VISUALIZADOR,
    project: { key: 'P-07', name: 'Visualizador de cartera · filtros' },
    fetched_at: '2026-10-07T09:51:00-05:00',
    ci_main: CI_MAIN_VISUALIZADOR,
    deploy: DEPLOY_VISUALIZADOR,
    commits: COMMITS_VISUALIZADOR,
    commits_total: COMMITS_VISUALIZADOR_TOTAL,
    commits_period: 'jue 1 a mié 7 oct',
    commits_authors: 2,
    open_pull_requests: [PR_41],
    last_closed_pull_request: PR_40,
    issues: [ISSUE_57, ISSUE_39, ISSUE_35],
    releases: RELEASES_VISUALIZADOR,
    planned_release: PLANNED_RELEASE_VISUALIZADOR,
};

/** The "Cuando una sección no tiene datos" states: no Actions, no PR, no issues, no releases yet. */
export const PRODUCT_GITHUB_QUIET: ProductGitHub = {
    ...PRODUCT_GITHUB_VISUALIZADOR,
    ci_main: { status: 'none', branch: 'main', commit_sha: null, run_at: null, summary: null, url: null },
    deploy: null,
    open_pull_requests: [],
    last_closed_pull_request: null,
    issues: [],
    releases: [],
    planned_release: null,
    commits: COMMITS_VISUALIZADOR.slice(0, 2),
    commits_total: 2,
    commits_authors: 1,
};

// ---- repository link dialog -------------------------------------------------

/** D-RepoLink for Cámaras (robotcamara exists on GitHub but is not linked yet). */
export const repoLinkCamaras = (open: boolean): RepoLinkSetup => ({
    repos: REPOS,
    target: { kind: 'product', key: 'robotcamara', name: 'Cámaras', product_key: null },
    organization: GITHUB_ORG,
    open,
    defaults: { repo: LINK_DRAFT_ROBOTCAMARA.repo, branch: LINK_DRAFT_ROBOTCAMARA.default_branch, read_docs: true, receive_events: true },
    pending_note: 'El release que falló el 5 oct se reprocesa solo al vincular.',
});

/** Same dialog from a project summary (sample: P-06 shown without its repository). */
export const repoLinkProject = (open: boolean): RepoLinkSetup => ({
    repos: REPOS,
    target: { kind: 'project', key: 'P-06', name: 'Toma Pedidos · descuentos por volumen', product_key: 'toma-pedidos' },
    organization: GITHUB_ORG,
    open,
    defaults: { repo: `${GITHUB_ORG}/toma-pedidos`, branch: 'main', read_docs: true, receive_events: true },
    pending_note: null,
});

// ---- task issue and PR ------------------------------------------------------

const VISUALIZADOR_REPO = { full_name: LINK_VISUALIZADOR.repo.full_name, url: LINK_VISUALIZADOR.repo.url };

const GITHUB_LOGIN = '[USUARIO DE GITHUB]';

export const ISSUE_FORM_T4: IssueFormSetup = {
    open: false,
    assignees: [
        { ...PEOPLE.jhon, github_login: GITHUB_LOGIN },
        { ...PEOPLE.leonardo, github_login: GITHUB_LOGIN },
    ],
    assignee_id: PEOPLE.jhon.id,
    label_options: ['gestor-ia', 'sap', 'backend', 'frontend', 'pruebas', 'documentación', 'diseño'],
};

const criteriaOf = (...ids: number[]) => CRITERIA_P07.filter((criterion) => ids.includes(criterion.id)).map(({ id, text }) => ({ id, text }));

/** T4 before "Crear issue en GitHub": no issue, no PR. */
export const TASK_GITHUB_T4: TaskGitHub = {
    repo: VISUALIZADOR_REPO,
    request_key: 'GIA-139',
    task_url: `${GESTOR_URL}/ai/proyectos/P-07/tareas/T4`,
    criteria: criteriaOf(13, 15),
    issue: null,
    pull_request: null,
    ci: null,
    events: [],
    issue_form: ISSUE_FORM_T4,
};

/** T4 right after creating issue #57. */
export const TASK_GITHUB_T4_CREATED: TaskGitHub = {
    ...TASK_GITHUB_T4,
    issue: ISSUE_57,
    issue_form: null,
    events: [{ id: 3, kind: 'issue_created', title: 'Se creó el issue #57 en GitHub', at: ISSUE_57.created_at, note: PEOPLE.jhon.name }],
};

/** T3 "Endpoint de zonas" in review because PR #41 mentions GIA-139 (D-GitHubPhone). */
export const TASK_GITHUB_T3: TaskGitHub = {
    repo: VISUALIZADOR_REPO,
    request_key: 'GIA-139',
    task_url: `${GESTOR_URL}/ai/proyectos/P-07/tareas/T3`,
    criteria: criteriaOf(12),
    issue: null,
    pull_request: PR_41,
    ci: CI_PR_41,
    events: [
        { id: 13, kind: 'ci_passed', title: 'CI pasó en el PR #41', at: CI_PR_41.run_at!, note: CI_PR_41.summary },
        { id: 12, kind: 'moved_review', title: 'Pasó a En revisión por el PR #41', at: '2026-10-06T17:13:00-05:00', note: 'automático, el PR menciona GIA-139' },
        { id: 11, kind: 'moved_progress', title: 'Pasó a En progreso', at: '2026-10-05T09:00:00-05:00', note: PEOPLE.leonardo.name },
    ],
    issue_form: null,
};

/** The board gets this right after "Crear issue" (toast + "Issue #57 nuevo"). */
export const ISSUE_CREATED_T4: { task_key: string; issue: GitHubIssue; repo_full_name: string } = {
    task_key: 'T4',
    issue: ISSUE_57,
    repo_full_name: VISUALIZADOR_REPO.full_name,
};

// ---- release page -----------------------------------------------------------

type ReleasePage = PageProps<ReleasesShowProps>;

const PORTAFOLIO = productBySlug('portafolio');

const releaseSuggestion: ReleasePage = {
    ...sharedFor('jhon'),
    product: { slug: PORTAFOLIO.slug, name: PORTAFOLIO.name, company_id: PORTAFOLIO.company_id },
    release: RELEASE_PORTAFOLIO_182,
    suggestion: SUGGESTION_RELEASE_NOTES_182,
    ai: 'ready',
    ai_usage: null,
    // vie 2 oct, 4:02 p. m.: the release arrived a few minutes ago.
    now: '2026-10-02T16:02:00-05:00',
};

const NOTES_ACCEPTED_AT = '2026-10-02T16:06:00-05:00';

export const variants: Record<string, MockVariant<ReleasePage>> = {
    release: { page: 'Ai/Releases/Show', label: 'Versión · Portafolio v1.8.2 con sugerencia de notas', props: releaseSuggestion },
    'release-notes-accepted': {
        page: 'Ai/Releases/Show',
        label: 'Versión · notas aceptadas, falta avisar',
        props: {
            ...releaseSuggestion,
            release: { ...RELEASE_PORTAFOLIO_182, user_notes: RELEASE_USER_NOTES_182 },
            suggestion: { ...SUGGESTION_RELEASE_NOTES_182, state: 'accepted', accepted: { by: PEOPLE.jhon.name, at: NOTES_ACCEPTED_AT } },
            now: '2026-10-02T16:08:00-05:00',
        },
    },
    'release-sent': {
        page: 'Ai/Releases/Show',
        label: 'Versión · aviso enviado a Andrés',
        props: {
            ...releaseSuggestion,
            release: RELEASE_PORTAFOLIO_182_NOTIFIED,
            suggestion: { ...SUGGESTION_RELEASE_NOTES_182, state: 'accepted', accepted: { by: PEOPLE.jhon.name, at: NOTES_ACCEPTED_AT } },
            now: NOW_ISO,
        },
    },
    'release-ai-off': {
        page: 'Ai/Releases/Show',
        label: 'Versión · sugerencias apagadas (tope del mes)',
        props: { ...releaseSuggestion, suggestion: null, ai: 'off_cap', ai_usage: AI_USAGE_CAP },
    },
    'release-no-permission': {
        page: 'Ai/Releases/Show',
        label: 'Versión · sin permiso (sin ai.manage)',
        props: { ...releaseSuggestion, ...without('leonardo', 'ai.manage'), suggestion: null, ai: 'hidden' },
    },
};
