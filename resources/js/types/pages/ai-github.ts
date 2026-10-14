/**
 * Gestor IA · GitHub (Stage D, boards D-RepoLink, D-ProductGitHub,
 * D-TaskIssue, D-GitHubPhone, D-ReleaseNotes). Stage D adds sections and
 * dialogs to existing pages; only the release page is new.
 *
 * GitHub data comes from the webhook events the server stored
 * (`POST /api/github/webhook`, `X-Hub-Signature-256` verified, processed by
 * the queue). Pages never call GitHub; links to github.com open in a new tab.
 * Secrets (app private key, webhook secret, tokens) are never props.
 *
 * Inertia components and their props:
 * - `Ai/Releases/Show`   ReleasesShowProps  D-ReleaseNotes (GestorLayout tab "products")
 * - Sections typed here and used by other pages:
 *   - `ProductGitHub` + `RepoLinkSetup` → `Ai/Products/Show` (`github`, `repo_link`, see ai-knowledge.ts)
 *   - `RepoLinkSetup`                   → `Ai/Projects/Show` (`repo_link`, see ai-projects.ts)
 *   - `TaskGitHub`                      → `Ai/Projects/Board` (`selected_task_github`) and `Ai/Tasks/Show` (`github`)
 *
 * Ziggy routes used:
 * - `ai.products.repository.store`  POST  `{ product: slug }` body `RepoLinkPayload`  "Vincular" in the repo dialog
 *                                        (back to `ai.products.show`; errors keyed `repo`, `branch`). Reprocesses the
 *                                        failed events of that repo (e.g. the `release` of 5 oct).
 * - `ai.products.show`              GET   `{ product: slug }` query `{ vincular?: 1 }` deep link that renders the page
 *                                        with `repo_link.open = true` (used by "Vincularlo" in the GitHub admin page)
 * - `ai.projects.repository.store`  POST  `{ project: key }` body `RepoLinkPayload`  same dialog on the project summary
 * - `ai.tasks.issue.store`          POST  `{ project: key, task: key }` body `IssuePayload`  "Crear issue" (back to the
 *                                        same page; the server sets flash success and `issue_created` on the board)
 * - `ai.releases.show`              GET   `{ product: slug, release: tag }`  version row in the product, notice link
 * - `ai.releases.notes.update`      PUT   `{ product: slug, release: tag }` body `ReleaseNotesPayload`  "Guardar notas"
 * - `ai.releases.notify`            POST  `{ product: slug, release: tag }` body `ReleaseNotesPayload`  "Avisar a N
 *                                        solicitantes" (saves the notes and sends Telegram + bell to each requester)
 * - `ai.suggestions.discard`        POST  `{ suggestion: id }`  "Descartar" on an AI suggestion (shared with T4)
 * - `ai.suggestions.regenerate`     POST  `{ suggestion: id }`  "Volver a sugerir" (shared with T4)
 * - `ai.products.show`, `ai.products.index`, `ai.requests.show`, `ai.docs.repo`  GET  plain links
 *
 * Permissions:
 * - `ai.github.manage`  linking repositories and creating issues. Without it the buttons are hidden, the server
 *                       sends `repo_link: null` / `issue_form: null` and refuses the writes.
 * - `ai.manage`         the release page and its AI suggestion (NoPermission without it). Requesters never see
 *                       suggestions, technical notes or GitHub internals; they only get the notice text.
 */
import type { AcceptanceCriterion, PersonRef, Product } from '@/types/ai';
import type {
    CiRun,
    CommitSummary,
    Deploy,
    GitHubIssue,
    GitHubRepo,
    GitHubRepoLink,
    PlannedRelease,
    PullRequest,
    Release,
    ReleaseSummary,
} from '@/types/github';
import type { AiAvailability, AiUsage, Suggestion } from '@/types/suggestions';
import type { FormErrors } from '@/types/shared';

// ---- repository link --------------------------------------------------------

/** Body of `ai.products.repository.store` / `ai.projects.repository.store`. */
export interface RepoLinkPayload {
    /** `full_name`: "GrupoRyR/robotcamara". */
    repo: string;
    /** Source of CI and last deploy. */
    branch: string;
    /** Show README, CLAUDE.md and docs/ in Documentación. */
    read_docs: boolean;
    /** Receive PR, CI and release events. */
    receive_events: boolean;
}

/** The "Vincular repositorio" dialog. Null without `ai.github.manage`. */
export interface RepoLinkSetup {
    /** Repositories of the organization with the app installed, in this order. */
    repos: GitHubRepo[];
    /** What is being linked; a project may reuse its product's repository. */
    target: { kind: 'product' | 'project'; key: string; name: string; product_key: string | null };
    /** "GrupoRyR". */
    organization: string;
    /** Open on load (`?vincular=1`, or back from a failed store). */
    open: boolean;
    defaults: RepoLinkPayload;
    /** "El release que falló el 5 oct se reprocesa solo al vincular."; null when nothing is pending. */
    pending_note: string | null;
}

// ---- product activity -------------------------------------------------------

/** GitHub section of `Ai/Products/Show`, built from stored webhook events. */
export interface ProductGitHub {
    link: GitHubRepoLink;
    /** Project that shares the repository ("vinculado al proyecto P-07"). */
    project: { key: string; name: string } | null;
    /** When the server last refreshed this data ("datos de hace 2 min"). */
    fetched_at: string;
    /** Latest `workflow_run` on the main branch; `status: 'none'` = the repo has no GitHub Actions. */
    ci_main: CiRun;
    /** Last `push` to main; null when none arrived yet. */
    deploy: Deploy | null;
    /** Newest first, at most 5 of `commits_total`. */
    commits: CommitSummary[];
    commits_total: number;
    /** "jue 1 a mié 7 oct". */
    commits_period: string;
    /** Distinct authors in the period. */
    commits_authors: number;
    open_pull_requests: PullRequest[];
    /** "Último cerrado: #40, sin fusionar · su CI falló el 3 oct". */
    last_closed_pull_request: PullRequest | null;
    /** Newest first, at most 3. */
    issues: GitHubIssue[];
    /** Newest first. */
    releases: ReleaseSummary[];
    planned_release: PlannedRelease | null;
}

// ---- task issue and PR ------------------------------------------------------

/** Body of `ai.tasks.issue.store`. The label `gestor-ia` is always added by the server too. */
export interface IssuePayload {
    title: string;
    /** Markdown: description, criteria checklist, link back (lib/issue `issueBody`). */
    body: string;
    labels: string[];
    assignee_id: number | null;
}

export interface IssueAssignee extends PersonRef {
    /** "@leoh"; "[USUARIO DE GITHUB]" while unknown; null = no GitHub account linked. */
    github_login: string | null;
}

/** "Crear issue en GitHub". Null without `ai.github.manage`, without a linked repo, or once the task has an issue. */
export interface IssueFormSetup {
    /** Open on load (back from a failed store). */
    open: boolean;
    assignees: IssueAssignee[];
    /** Default assignee (the task's first assignee). */
    assignee_id: number | null;
    /** Labels already used in the repository, for "Agregar". */
    label_options: string[];
}

export type TaskGitHubEventKind = 'ci_passed' | 'ci_failed' | 'moved_review' | 'moved_done' | 'moved_progress' | 'issue_created' | 'pr_opened' | 'pr_merged';

/** One line of the task history that came from GitHub or the board. */
export interface TaskGitHubEvent {
    id: number;
    kind: TaskGitHubEventKind;
    /** "CI pasó en el PR #41". */
    title: string;
    at: string;
    /** "2 de 2 pruebas automáticas", "automático, el PR menciona GIA-139", "Leonardo Herrera". */
    note: string | null;
}

/** GitHub block of a task (board drawer and phone task page). */
export interface TaskGitHub {
    /** Repository of the project; null = not linked (the button explains it). */
    repo: Pick<GitHubRepo, 'full_name' | 'url'> | null;
    /** "GIA-139": goes in the issue title and links PRs. */
    request_key: string;
    /** Absolute URL of the task in the Gestor, for the issue's link back. */
    task_url: string;
    /** Criteria texts for the issue checklist (the related ones of the task). */
    criteria: Pick<AcceptanceCriterion, 'id' | 'text'>[];
    issue: GitHubIssue | null;
    pull_request: PullRequest | null;
    /** CI of the open PR. */
    ci: CiRun | null;
    /** Newest first. */
    events: TaskGitHubEvent[];
    issue_form: IssueFormSetup | null;
}

// ---- release page -----------------------------------------------------------

/** Body of `ai.releases.notes.update` and `ai.releases.notify`. */
export interface ReleaseNotesPayload {
    user_notes: string;
    /** The suggestion the text started from (records "Basado en una sugerencia"); null when typed by hand. */
    suggestion_id: number | null;
}

export interface ReleasesShowProps {
    product: Pick<Product, 'slug' | 'name' | 'company_id'>;
    release: Release;
    /** AI draft of the user notes; null when off, hidden or never generated. */
    suggestion: Suggestion<'release_notes'> | null;
    ai: AiAvailability;
    ai_usage: AiUsage | null;
    errors?: FormErrors;
    now: string;
}
