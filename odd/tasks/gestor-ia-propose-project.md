# Gestor IA · Proponer proyecto (IT shortcut)

Locator: `odd/tasks/gestor-ia-propose-project.md` · Engram mirror: `odd/gestor-ia-propose-project/tasks`

## Objective
Let an `ai.manage` user propose a project in one step.
- **Holds `ai.projects.approve` (Jhon, Edward): self-approved.** One server transaction creates the request in the proposer's name, records the evaluation and estimate, records the approval "aprobada por quien la propuso", and converts it to a project "Planificada". The page then redirects to the project.
- **Lacks `ai.projects.approve`:** the request is created already evaluated and goes to the normal approval round. The page then redirects to the request.

## Source
- **Mockups:** design canvas https://claude.ai/artifact/VpJ8rdFwC3jeCVBiSjfn2m, page "Etapa B · Gestor IA", row "Proponer proyecto (TI)". Boards `B-ProposeEntry`, `B-ProposeSelf`, `B-ProposeApproval`, `B-ProposePhone`.
- **Approval:** Jhon approved them on 2026-10-21. The "Estimado" section (hours per person, delivery date) stays in both paths.
- **Extra decision from Jhon (2026-10-21):** every self-approved proposal sends an informative notice to Edward Ramanan describing what was proposed. Generalized rule: the other holders of `ai.projects.approve` (today Edward when Jhon proposes, Jhon when Edward does). The aside says so.
- **Agreed with the app agent:**
  - The company comes from the chosen area.
  - The proposer receives no "Recibimos tu solicitud" or "Llegó una solicitud nueva".
  - Page props: `can_self_approve`, `approvers`, `team`, areas grouped by company, `apps`, `wip`, `today`, and optional next keys.

## Scope (design-owned)
- `resources/js/types/pages/`
- `resources/js/Pages/Ai/Proposals/`
- `resources/js/Components/ai/**`, for reused or extracted form parts
- `resources/js/Layouts/GestorLayout.tsx` (the "Pedir un desarrollo" link)
- The Bandeja and Proyectos pages (header buttons)
- `resources/js/lib/`
- `resources/js/mocks/`
- `docs/design/`

## Constraints
- Reuse the existing wizard and evaluation vocabularies, option lists and helpers (`teamHours`, `lib/priority`, `urgencyOptions`, size bars, ConvertToProjectDialog labels). Never fork the option lists.
- Page contract as usual. One Inertia `useForm` POST to `ai.proposals.store`.
- Spanish, sentence case, es-CO formats, 44 px targets.
- Test first for any new pure helper (for example the form summary or "qué pasa al crear" lines and client validation).

## Delivery strategy
`ask-on-risk`; forecast about 900 authored lines. Branch `claude/diseno-proponer-proyecto` from `main` (`6c4ee6c`), one PR.

## Tasks
- [x] T1 Page `Ai/Proposals/Create`.
  - Types, helpers (tests first), page, mocks: self-approve, to approval, validation errors, phone.
  - Entry points: Bandeja header, Proyectos header and the GestorLayout "Pedir un desarrollo" link, all gated by `ai.manage`.
  - Notification kind for the new event in `lib/notifications.ts`.
  - **Route:** delegated.
- [x] T2 Contract `docs/design/propose-project-contract.md`, docs index, PR.
  - **Route:** inline.

## Acceptance criteria
- `npm run typecheck`, `npm test`, `npm run build` and `npm run build:preview` pass.
- Variants are visible in the gallery.
- The Proyecto section and the "Crear proyecto" button render only when `can_self_approve` is true.
- The aside names who gets the notice.

## Progress
- 2026-10-21: branch created, document written.
- 2026-10-21: T1 done (delegated writer, uncommitted): page, `lib/proposal.ts` (RED: module missing + `ai.project.proposed` icon → GREEN 27 + 1 tests), mocks `selfApprove`/`toApproval`/`withErrors`/`noAppsNoKeys`, entry points, notification icon; typecheck, 671 tests, build and build:preview pass; gallery checked at 375/768/1280/1440. Parent deduplicated the wizard's urgency options to the shared `urgency.ts`. Commit 6765aac.
- 2026-10-21: T2 inline: `docs/design/propose-project-contract.md`, docs index, PR.

## Next step
Merge the PR; the app agent builds `ai.proposals.*` and the `ai.project.proposed` notice.
