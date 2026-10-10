# Stage D · Conexiones (backend)

Source of truth: `PROMPT_GESTOR_IA_APP.md` §6.4.6 (GitHub), §6.4.7 (Claude: brief + AI suggestions), §7.2 (webhooks), §8. Page contracts (design PR GrupoRyR/GESTOR-TI#20, merged into this chain as `1e39cc7`):
`docs/design/stage-d-contract.md`, `resources/js/types/github.ts`, `types/suggestions.ts`, `types/pages/{admin,ai-knowledge,ai-projects,ai-github,ai-triage}.ts`, `lib/{brief,githubKeys,issue}.ts`.

## Objective
Connect the Gestor IA with GitHub (GitHub App, repo links, one-click issues, signed webhooks processed on the database queue, releases that create product versions and notify requesters, repo docs), expose whitelisted "Brief para Claude" inputs, and add AI suggestions (summary, duplicates, criteria, size, release notes) that IT always confirms.

## Decisions
- 2026-10-10 (orchestrator, per user request "confirma o renombra" applied since Stage C): the route names added by design are confirmed as-is.
- GitHub auth: GitHub App (design assumes `install_url` + private key). JWT RS256 signed with `openssl_sign` (no new dependency); installation token cached < 1 h. All GitHub calls through Laravel `Http` (fakeable). Secrets (`GITHUB_APP_PRIVATE_KEY_PATH`, `GITHUB_WEBHOOK_SECRET`) only in `.env`; props carry booleans.
- GitHub admin routes register outside the `can:settings.manage` Integraciones group, under `can:ai.github.manage` (Líder IA has it, not `settings.manage`). `admin.integrations.github.update` (organization) stays `settings.manage` per type header.
- Key matching mirrors `lib/githubKeys.ts`: `(?<![A-Za-z0-9])gia-(\d+)(?![A-Za-z0-9])`, case-insensitive, over title, body and branch.
- AI suggestions reuse `AiRuntime::run()` (logs `ai_interactions`, enforces the cap). AI input whitelist: title, problem, current process, expected result, app, open-request titles. Never auto-applied.

## Delivery
- Strategy cached: stacked-to-main. Slices `stage-d/NN-*` stacked on `stage-c/06-pending-forms`. RDD off.
- Checks: `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm test`, `npm run build`.
- Forecast: ~3,000+ authored lines (6 slices).

## Tasks
- [x] D0 · Merge design PR #20 (`1e39cc7`): no conflicts; typecheck, 476 vitest and build green.
- [ ] D1 · GitHub foundation: `github_repositories` (installed repos + polymorphic product/project link: branch, read_docs, receive_events, linked_by/at), `github_webhook_events`, `users.github_login`; `GitHubApp` client (JWT, installation token, repos, contents, issues, create repo); `GiaKeys` matcher; page `Admin/Integrations/GitHub` + `admin.integrations.github{,.update,.events.retry,.disconnect}`; Integraciones card. Route: delegated (writer trigger).
- [ ] D2 · Webhook: `POST /api/github/webhook` (HMAC `X-Hub-Signature-256`, rate limit), store event, queued processor; `issues`/`pull_request` (task → En revisión / Hecho, activity), `workflow_run` (CI), `push` to main (deploy), `release` (product version + included `GIA-…` since previous release); failed-event reprocess. Route: delegated.
- [ ] D3 · Repos, issues and docs: `ai.products.repository.store`, `ai.projects.repository.store`, `ai.projects.repository.create` (private repo with README, CLAUDE.md, docs/), `ai.tasks.issue.store`; props `Ai/Products/Show.github|repo_link`, `Ai/Projects/Show.repo_link|repo_create`, Board `selected_task_github|issue_created`, `Ai/Tasks/Show.github`; `Ai/Docs/Repo` connected. Route: delegated.
- [ ] D4 · Releases: page `Ai/Releases/Show`, `ai.releases.notes.update`, `ai.releases.notify` (bell + Telegram to each requester, confirmed notes only). Route: delegated.
- [ ] D5 · Brief para Claude: whitelisted `brief` inputs + `brief_state` on request/task/project/board, `brief_omitted`; tests that vault, credentials, costs, rates, hours and internal notes never appear. Route: delegated.
- [ ] D6 · AI suggestions: `ai_suggestions`, agents, `ai.requests.suggestions.store`, `ai.suggestions.{accept,discard,regenerate,cancel}`, `ai.requests.compare`, `ai.requests.criteria.update`, `size_suggestion_id` on evaluation, `ai_availability`/`ai_usage`/`merge_into`; release-notes suggestion. Route: delegated.
- [ ] D7 · Close: full checks, deploy doc (GitHub App setup, env keys), PRs.

## Progress / evidence

## Open questions (for Jhon)
- GitHub organization name, App registration (app id, private key, webhook secret go to `.env`).
- `github_login` for each IT member (issue assignees).
