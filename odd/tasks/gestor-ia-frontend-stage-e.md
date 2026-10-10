# Gestor IA frontend · Stage E (Conector MCP)

Locator: `odd/tasks/gestor-ia-frontend-stage-e.md` · Engram mirror: `odd/gestor-ia-frontend-stage-e/tasks`

## Objective
Implement the approved Stage E screens as an Inertia page under Perfil, with typed props, mocks and gallery variants, ready for the app agent. The screens are on the design canvas https://claude.ai/artifact/VpJ8rdFwC3jeCVBiSjfn2m, page "Etapa E · Conector MCP", 7 boards: tokens, create, show-once, setup, activity and revoke, phone, and states.

## Problem / why
`PROMPT_GESTOR_IA_DISENO.md` §4 Etapa E and `PROMPT_GESTOR_IA_APP.md` 6.4.7 step 3. Jhon approved the mockups on 2026-10-10. The office token's last use is "ayer 5:38 p. m.", the rejected read-only call.

## Scope (design-owned folders only)
- `resources/js/Pages/Profile/**`
- `resources/js/Components/mcp/**`
- `resources/js/Components/ui/**` (additive only)
- `resources/js/types/`
- `resources/js/mocks/`
- `resources/js/lib/`
- `docs/design/`

Out of scope: the MCP server itself, PHP and routes.

## Constraints
- **Permission:** only `ai.mcp.use` (today Jhon and Leonardo). Each person manages only their own tokens.
- **Token value:** it only exists in a `created_token` prop right after the store request. It is never in URLs or logs. Afterwards only the last 4 characters are shown. The response uses `encryptHistory` and no-store.
- **Tools:** `buscar_solicitudes` and `ver_solicitud` (read); `crear_tarea`, `actualizar_tarea`, `agregar_nota` and `adjuntar_documento` (write). Permission is "Solo lectura" or "Lectura y escritura".
- **Claude menus:** never invent Claude's menu paths; keep the `[PASOS EN CLAUDE]` placeholders.

## Delivery strategy
`stacked-to-main` chain. Branch `claude/diseno-gestor-ia-etapa-e` is stacked on Stage D (`claude/diseno-gestor-ia-etapa-d`, PR #20). There is one PR with base = the Stage D branch.

## Tasks
- [x] T1 Conector page.
  - **Pages and props:** `Profile/Mcp` with tokens, create dialog, show-once, setup section, activity and revoke, and states. Types `types/pages/profile-mcp.ts`, mocks `mocks/profile-mcp.ts`.
  - **Components and helpers:** components in `Components/mcp/`. Test-first helpers: expiry date and days left, last4 masking, tool list per permission, and the `claude mcp add` command builder.
  - **Profile link:** add a link from `Profile/Edit`.
  - **Route:** delegated (2+ non-trivial files).
- [x] T2 Handoff.
  - `docs/design/stage-e-contract.md`, a gallery check and the PR.
  - **Route:** inline.

## Acceptance criteria
- `npm run typecheck`, `npm test` and `npm run build:preview` pass.
- Every variant is in the gallery, in light and dark, at 390 and 1440.
- The types header lists routes and permissions.

## Checks
- Typecheck.
- Vitest helpers, written test-first.
- Preview build.
- Visual readback.

## Progress
- 2026-10-10: branch created from the Stage D head (`8810f63`, 476 tests); document created.
- T1 `070d28e` Profile/Mcp (12 variants), Components/mcp, lib/mcp.ts test-first (20 tests) — 496 tests. Delegated writer checked variants; parent spot-checked show-once at 390 dark and fixed the squeezed "Siguiente paso" card.
- T2 inline: `docs/design/stage-e-contract.md`; PR stacked on Stage D.

## Next step
Jhon reviews the gallery; merge the stacked PRs in order (#8, #10, #11, #20, this one).
