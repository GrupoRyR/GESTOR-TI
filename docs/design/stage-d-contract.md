# Stage D · Frontend contract for the app agent (Conexiones)

Builds on the [Stage A](stage-a-contract.md), [Stage B](stage-b-contract.md) and [Stage C](stage-c-contract.md) contracts. The approved mockups are on the design canvas, page "Etapa D · Conexiones". Stage D mostly **adds** optional props, sections and dialogs to Stage B pages. When a new prop is missing, the page renders as in Stage B.

**The source of truth is the types files.** Each one starts with every route, method, param, body and permission it uses.

| Types file | What Stage D adds |
|---|---|
| `types/pages/admin.ts` | `Admin/Integrations/GitHub` (new). The GitHub card on `Admin/Integrations/Index` links to it. |
| `types/pages/ai-knowledge.ts` | `Ai/Products/Show`: `github` (activity) and `repo_link` (link dialog). `Ai/Docs/Repo` reads real repo files. |
| `types/pages/ai-projects.ts` | `Ai/Projects/Show`: `repo_link`, `brief`, `brief_omitted`, `repo_create`. `Ai/Projects/Board`: `selected_task_github`, `selected_task_brief`, `issue_created`. `Ai/Tasks/Show`: `github`, `brief`. |
| `types/pages/ai-github.ts` | `Ai/Releases/Show` (new): release, included requests, user-notes suggestion, notify requesters. |
| `types/pages/ai-triage.ts` | `Ai/Requests/Show` (IT view): `brief`, `suggestions`, `ai_availability`, `ai_usage`, `merge_into`. `Ai/Requests/Compare` (new). |

- **Domain types:** `types/github.ts` and `types/suggestions.ts`.
- **Example data:** `mocks/github-fixtures.ts`.
- **Gallery:** open any page with "Ver props" in `npm run dev:preview`.

## GitHub
- **Data source:** GitHub data comes as props built by the server from the stored webhook events (`issues`, `pull_request`, `release`, `workflow_run`, `push` to the main branch). The page never calls GitHub.
- **Linking:** an event links to a request by the key `GIA-123` in the title or body, or by the branch `gia-123-…`. `lib/githubKeys.ts` documents the expected matching.
- **Permission `ai.github.manage`** covers:
  - the admin page;
  - linking repositories (`ai.products.repository.store`, `ai.projects.repository.store`);
  - creating issues (`ai.tasks.issue.store`);
  - creating a repo with the initial `CLAUDE.md` (`ai.projects.repository.create`).

  Without it, the server sends `repo_link`, `issue_form` and `repo_create` as `null`.
- **Secrets:** the webhook secret and tokens are never props; only `webhook.configured: boolean`.
- **Issues:** the title and body follow `lib/issue.ts`. The `gestor-ia` label is always included, and the body says it does not include costs or internal notes.
- **Releases:** a published release creates the product version. "Avisar a N solicitantes" (`ai.releases.notify`) sends the confirmed user notes; the AI suggestion is never sent as-is.

## Brief para Claude
- **No AI, no routes:** the app builds it from a template on the client with `lib/brief.ts`. Copy and download are client-only.
- **Inputs:** `RequestBriefInput`, `TaskBriefInput` and `ProjectBriefInput`. The server sends them only with `ai.manage` and only with whitelisted fields.
- **Never included:** the vault, credentials, costs, rates, hours or internal notes. The builders also drop them, and tests cover it.
- **Requester view:** never gets a brief.
- **`ai.projects.repository.create`:** POST `{project}`, body `{name, claude_md}`. It creates a private repo with README.md, CLAUDE.md and docs/, and links it.

## AI suggestions
- **Permission:** only `ai.manage`. Without it the server sends `ai_availability: 'hidden'`, and requesters never receive suggestions.
- **Never automatic:** a suggestion is never applied on its own.
  - `ai.suggestions.accept` stores the (possibly edited) value.
  - "Usar M" only fills the size selector. `ai.requests.evaluation.update` receives `size_suggestion_id` when the saved size matches.
- **Routes:**
  - `ai.requests.suggestions.store` (POST `{request}`)
  - `ai.suggestions.accept` / `discard` / `regenerate` / `cancel` (POST `{suggestion}`)
  - `ai.requests.compare` (GET `{request, other}`)
  - The request page uses `only: ['suggestions']`.
- **Duplicates:** the rule-based duplicate keeps its amber banner (Stage B). AI duplicate cards that point to a request the rules already flagged are hidden (`visibleDuplicateSuggestions`).
- **Availability:** `ai_availability` is `ready`, `off_no_key`, `off_cap` or `hidden`. Admins see one note with a link to Integraciones; everything else keeps working.
- **What the AI receives:** title, problem, current process, expected result, app, and the titles of open requests (for duplicates). It never receives credentials, vault content, rates, costs, salaries, client data, attachments or internal notes.

## Route names added by design (confirm or rename)
- **GitHub admin:**
  - `admin.integrations.github`
  - `admin.integrations.github.events.retry`
  - `admin.integrations.github.disconnect`
- **Repositories and issues:**
  - `ai.products.repository.store`
  - `ai.projects.repository.store`
  - `ai.projects.repository.create`
  - `ai.tasks.issue.store`
- **Releases:**
  - `ai.releases.show`
  - `ai.releases.notes.update`
  - `ai.releases.notify`
- **Suggestions:**
  - `ai.requests.suggestions.store`
  - `ai.suggestions.accept`, `ai.suggestions.discard`, `ai.suggestions.regenerate`, `ai.suggestions.cancel`
  - `ai.requests.compare`
  - `ai.requests.criteria.update` (the IT view can now edit acceptance criteria)

## Open points
- **Placeholders:** `[ORG]` (GitHub organization), `[DOMINIO DEL GESTOR]`, `[USUARIO DE GITHUB]`, `[TOPE MENSUAL]`, `[GASTO DEL MES]`, `[MÍNIMO DE PALABRAS]`.
- **Board brief:** the brief panel opens over the task drawer, which stays visible behind it.
- **Stage E (MCP connector):** shown only as a disabled step "Conector MCP · etapa E".
