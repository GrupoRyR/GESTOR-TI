# Proponer proyecto · Frontend contract for the app agent

This is an IT shortcut, for `ai.manage` only: one form that turns the proposer's own idea into a request and, when they can approve, a project. It builds on [stage-b-contract.md](stage-b-contract.md).

Jhon approved the mockups on 2026-10-21. They are on the design canvas, page "Etapa B · Gestor IA", row "Proponer proyecto (TI)".

**The source of truth is `types/pages/ai-proposals.ts`.** Its header lists every route, redirect and permission.

| Page (Inertia component) | Props | Mocks (gallery variants) |
|---|---|---|
| `Ai/Proposals/Create` | `ProposalsCreateProps` | `mocks/ai-proposals.ts`: `selfApprove`, `toApproval`, `withErrors`, `noAppsNoKeys` |

## Routes (design names; confirm or rename)
- `ai.proposals.create`: GET, the page.
- `ai.proposals.store`: POST, with body `ProposalStorePayload`.
- Both need `ai.manage`.

## What `ai.proposals.store` does
Everything happens in one transaction. If anything fails, nothing is created, and validation errors come back as Inertia `errors` keyed by payload field. The estimate rows use `hours.0.hours` style keys.

**With `ai.projects.approve` (`can_self_approve`)**
1. Create the request with the proposer as requester. The company comes from `area_id`; check that the area belongs to an active company the proposer can access.
2. Record the evaluation: `risk`, `urgency`, `size`, priority. The server recomputes the priority from `priority_suggested` and `priority_final`.
3. Record the estimate: `hours[]` and `due_date`.
4. Record an approval round with decision approved and actor = proposer, marked "Aprobada por quien la propuso".
5. Convert the request to a project "Planificada" with `project_name` and `start_date`.
6. Notify the other `ai.projects.approve` holders, sent to the page as `notify_on_self_approve`. Today that is Edward Ramanan when Jhon proposes. Use the new event **`ai.project.proposed`**, for example "P-08 · Jhon Castro propuso y aprobó un proyecto", with `url` = the project. Add it to `config/notifications.php` under Gestor IA, honoring each person's channel preferences. The bell already maps it to the project icon.
7. Redirect to `ai.projects.show {project}`.

**Without it**
1. Create the request, already evaluated, with its estimate.
2. Open the normal approval round with `approver_ids`. The approvers get the usual notices.
3. No project is created.
4. Redirect to `ai.requests.show {request}`.

**Both paths**
- The proposer receives neither "Recibimos tu solicitud" nor "Llegó una solicitud nueva".
- No costs or rates on this page: `team` carries people only. `external_costs` is not in the form; treat it as 0.

## Props
- `can_self_approve`
- `approvers`
- `notify_on_self_approve`
- `team` (PersonRef)
- `types`, the same step 1 options the wizard receives
- `areas`, grouped by company
- `apps`
- `wip`
- `today`, as YYYY-MM-DD in the Bogotá calendar
- Optional: `next_request_key` and `next_project_key`. Without them the summary uses generic wording.
- `draft` is gallery-only; the server never sends it.

## Entry points (frontend, gated by `ai.manage`)
- "Proponer proyecto" in the Bandeja header (`Ai/Inbox/Index`) and the Proyectos header (`Ai/Projects/Index`).
- "Pedir un desarrollo" at the right end of the Gestor IA tab strip, from 1024 px wide, linking to the existing wizard `ai.requests.create`.

## Body rules (mirror them on the server)
- `title`: at most 120 characters.
- `problem`: at least 20 characters.
- `hours_per_week`: 0.5 to 60. `people`: 1 to 500. Both may be empty only for `idea`.
- `product_slug`: required for `improvement` and `bug`.
- `due_date`: YYYY-MM-DD.
- Self-approve only: `project_name` and `start_date` are required; `approver_ids` is absent.
- Otherwise: `approver_ids` has at least 1 entry; `project_name` and `start_date` are absent.
