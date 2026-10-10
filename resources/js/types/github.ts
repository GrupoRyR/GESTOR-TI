/**
 * GitHub domain types (Stage D). Pages never call GitHub: the server builds
 * these props from the webhook events it stored (`POST /api/github/webhook`,
 * signature `X-Hub-Signature-256` verified, processed by the queue).
 *
 * Conventions:
 * - Dates: ISO 8601 instants with offset; the UI formats them in America/Bogota.
 * - Repository names are `owner/name` (`[ORG]/visualizador-cartera`).
 * - Secrets (app private key, webhook secret, tokens) are NEVER props; only
 *   `configured: boolean`.
 * - Work is linked by the request key `GIA-123` found in the title, body or
 *   branch name (`gia-123-…`), see lib/githubKeys.
 */

export type CiStatus = 'passing' | 'failing' | 'running' | 'none';

export type PullRequestState = 'open' | 'merged' | 'closed';

export type IssueState = 'open' | 'closed';

/** Webhook events the GitHub App subscribes to. `push` only for the main branch. */
export type WebhookEventType = 'issues' | 'pull_request' | 'release' | 'workflow_run' | 'push';

/** A GitHub account as the Gestor shows it: the Gestor person when known. */
export interface GitHubActor {
    /** "Leonardo Herrera" (Gestor person) or the GitHub login when unknown. */
    name: string;
    /** "@leoh"; placeholder "[USUARIO DE GITHUB]" while pending. */
    login: string | null;
}

/** Gestor work an event links to through its `GIA-123` key. */
export interface LinkedWork {
    request_key: string;
    project_key: string | null;
    /** "T3" inside the project. */
    task_key: string | null;
    task_title: string | null;
}

// ---- connection ---------------------------------------------------------

export interface GitHubPermission {
    /** "issues", "pull_requests", "contents", "releases", "actions". */
    scope: string;
    access: 'read' | 'write';
}

/** Administración › Integraciones › GitHub (`ai.github.manage`). */
export interface GitHubConnection {
    connected: boolean;
    /** GitHub App of the organization (preferred) or a fine-grained token. */
    kind: 'app' | 'token';
    /** "Gestor TI RYR". */
    app_name: string;
    /** "[ORG]" until the real name is confirmed. */
    organization: string;
    installed_repos_count: number;
    permissions: GitHubPermission[];
    /** The app private key lives in the server .env; only whether it is set. */
    private_key_configured: boolean;
    /** Where to change the installed repositories on github.com. */
    manage_url: string;
    webhook: {
        /** "https://[DOMINIO DEL GESTOR]/api/github/webhook". */
        url: string;
        /** Secret set and signature checked. The secret itself is never sent. */
        configured: boolean;
        events: WebhookEventType[];
        last_delivery_at: string | null;
    };
}

// ---- repositories -------------------------------------------------------

/** A repository the app is installed on. */
export interface GitHubRepo {
    /** "[ORG]/visualizador-cartera". */
    full_name: string;
    /** "visualizador-cartera". */
    name: string;
    url: string;
    private: boolean;
    default_branch: string;
    branches: string[];
    /** "Python", "TypeScript". */
    language: string | null;
    pushed_at: string | null;
    /** Product or project this repo is linked to; null = "Sin vincular". */
    linked_to: { kind: 'product' | 'project'; key: string; name: string } | null;
}

/** Link of a product or project to its repository (dialog "Vincular repositorio"). */
export interface GitHubRepoLink {
    repo: GitHubRepo;
    /** Source of CI and last deploy. */
    default_branch: string;
    /** Show README, CLAUDE.md and docs/ in Documentación. */
    read_docs: boolean;
    /** Receive PR, CI and release events. */
    receive_events: boolean;
    linked_at: string;
    linked_by: string;
}

// ---- activity -----------------------------------------------------------

export interface PullRequest {
    number: number;
    title: string;
    state: PullRequestState;
    ci: CiStatus;
    commits: number;
    author: GitHubActor;
    /** "gia-139-endpoint-zonas". */
    branch: string;
    /** "main". */
    base_branch: string;
    linked: LinkedWork | null;
    url: string;
    opened_at: string;
    merged_at: string | null;
    closed_at: string | null;
}

export interface GitHubIssue {
    number: number;
    title: string;
    state: IssueState;
    labels: string[];
    assignee: GitHubActor | null;
    /** True when created with "Crear issue en GitHub". */
    created_from_gestor: boolean;
    created_by: GitHubActor | null;
    created_at: string;
    closed_at: string | null;
    /** PR that closed it. */
    closed_by_pr: number | null;
    linked: LinkedWork | null;
    url: string;
}

/** Latest CI (`workflow_run`) on a branch. */
export interface CiRun {
    status: CiStatus;
    branch: string;
    /** Short SHA "a1b2c3d". */
    commit_sha: string | null;
    run_at: string | null;
    /** "2 de 2 pruebas automáticas". */
    summary: string | null;
    url: string | null;
}

/** Last deploy, from the `push` to the main branch. */
export interface Deploy {
    /** "Vercel", "GoDaddy". */
    provider: string;
    branch: string;
    commit_sha: string;
    deployed_at: string;
    trigger: 'push';
    url: string | null;
}

export interface CommitSummary {
    /** Short SHA. */
    sha: string;
    message: string;
    author: GitHubActor;
    committed_at: string;
    url: string;
}

// ---- releases -----------------------------------------------------------

/** Request included in a release; its requester gets the notice. */
export interface ReleaseRequest {
    key: string;
    title: string;
    /** Moved to "En producción" by the release. */
    status: 'in_production';
    requester: { name: string; company: string; area: string };
    /** PR where the key was found. */
    found_in_pr: number | null;
}

/** Row in a product's "Versiones" list. */
export interface ReleaseSummary {
    /** "v1.8.2". */
    tag: string;
    /** User-facing summary. */
    summary: string;
    published_at: string;
    request_keys: string[];
    /** Requesters already notified. */
    notified_count: number;
    url: string;
}

/** Version announced in the product but not released yet. */
export interface PlannedRelease {
    tag: string;
    summary: string;
    request_keys: string[];
}

/** Release page (`Ai/Releases/Show`). */
export interface Release extends ReleaseSummary {
    /** "[ORG]/portafolio". */
    repo: string;
    author: GitHubActor;
    included: ReleaseRequest[];
    /** Release body exactly as GitHub sends it (read-only Markdown). */
    technical_notes: string;
    /** Final user-facing notes Jhon confirmed; null until then. */
    user_notes: string | null;
    /** The Gestor created the product version from the release. */
    version_created: boolean;
    /** Requesters were notified (Telegram + bell); null = pending. */
    notified_at: string | null;
    /** "v1.8.1...v1.8.2". */
    compare: { label: string; url: string } | null;
}

// ---- webhook events -----------------------------------------------------

export type WebhookEventStatus = 'processed' | 'failed';

export interface WebhookEvent {
    id: number;
    type: WebhookEventType;
    /** "[ORG]/visualizador-cartera". */
    repo: string;
    received_at: string;
    status: WebhookEventStatus;
    /** Spanish, user-facing; null when processed. */
    error: string | null;
    /** `timeout`, `repo_not_linked`… */
    error_code: string | null;
}

/** Month counters on the GitHub admin page. */
export interface WebhookStats {
    /** "octubre". */
    month: string;
    received: number;
    processed: number;
    failed: number;
}
