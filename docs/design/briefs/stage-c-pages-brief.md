# Stage C pages · common brief for page writers (T1–T5)

Worktree: `C:\Users\Claude\Documents\PROYECTOS_CLAUDE\PAGINAS_WEB\GESTOR_TI\.claude\worktrees\diseno-gestor-ia-a5a314`. Branch: `claude/diseno-gestor-ia-etapa-c`. Windows, Git Bash.
- Do NOT commit.
- Keep browser and Playwright harnesses outside the worktree.
- Delete any `.playwright-mcp/` folder before returning.

## Skills to load before work
- `C:\Users\Claude\.claude\plugins\cache\claude-plugins-official\frontend-design\d182ca456ca0\skills\frontend-design\SKILL.md`

## Read first
- `odd/tasks/gestor-ia-frontend-stage-c.md`, `docs/design/stage-a-contract.md`, `docs/design/stage-b-contract.md`.
- Facts and rules: `docs/design/briefs/stage-c-mockups-brief.md`. Reconciled facts:
  - the import updates K-031, K-024 (Informe de antigüedad de cartera) and K-029 (Informe de compras pendientes por proveedor);
  - the vault item is `DEEPSEEK_API_KEY` · Gestor TI · producción, with rotation on 19 oct;
  - only Jhon and Leonardo have `assistant.use` today.
- Existing system:
  - `resources/js/Components/ui/index.ts`, `resources/js/Components/ai/index.ts`
  - `resources/js/Layouts/*.tsx`
  - `resources/js/lib/*.ts`
  - `resources/js/types/shared.ts`, `resources/js/types/ai.ts`
  - `resources/js/mocks/shared.ts` (`MockVariant`, `sharedFor`, `USERS`), `resources/js/mocks/ai-fixtures.ts`
  - after T1: `resources/js/types/assistant.ts`, `resources/js/mocks/assistant-fixtures.ts`, `resources/js/Layouts/AssistantLayout.tsx`, `resources/js/Components/assistant/index.ts`
- Page patterns to copy:
  - `resources/js/Pages/Ai/Vault/Index.tsx` (protected value, countdown, no-store)
  - `resources/js/Pages/Ai/Products/Show.tsx`
  - `resources/js/Pages/Ai/Inbox/Index.tsx`
  - `resources/js/Pages/Ai/Requests/Create.tsx` (stepper)
  - `resources/js/types/pages/ai-knowledge.ts`, `resources/js/mocks/ai-products.ts` (or the closest Stage B mock)
- Approved mockups (READ ONLY): `C:\Users\Claude\AppData\Local\Temp\claude\C--Users-Claude-Documents-PROYECTOS-CLAUDE-PAGINAS-WEB-GESTOR-TI--claude-worktrees-diseno-gestor-ia-a5a314\56383c3b-3b7e-452d-b8fe-3de6089fbd86\scratchpad\canvas\project\C-*.dc.html`. Reproduce them faithfully and responsively at 390 / 834 / 1440, in light and dark.

## Contract rules (the app agent wires these)
- **Pages:**
  - Signature: `export default function Name(props: PageProps<NameProps>)`. Pass props to the layout as `shared`. No fetching, no `usePage`.
  - Assistant pages use `AssistantLayout`; Stage B forms use `GestorLayout`.
  - Sending a chat message is an Inertia `router.post` / `useForm` to a named route. The server answers with the updated conversation props, so there is no streaming and no client fetch.
- **Types:**
  - `resources/js/types/pages/{file}.ts` starts with a comment block listing every Ziggy route (name, method, params/body) and every permission its pages use.
  - Never name a page prop `companies`, `auth`, `flash`, `modules`, `notifications_unread` or `current_company_id`.
- **Mocks:**
  - `resources/js/mocks/{file}.ts` exports `variants: Record<string, MockVariant<...>>` with `page` = the Inertia component name.
  - Build data from the fixtures; do not duplicate entities.
  - Include every designed variant (states, permission-less, phone-first cases).
- **Forms:** Inertia `useForm` with Laravel-shaped errors. Destructive or irreversible actions confirm with `ui/Dialog` / `ConfirmDialog`.
- **Permissions:**
  - `can()`: `assistant.use` for the chat, `assistant.knowledge.manage` for cards, import, drafts and unanswered questions, and `assistant.credentials` AND `credentials.view` for password delivery.
  - The vault (`ai.vault.access`) is never opened from the chat; the chat only links to it.
  - Credential values exist only in a dedicated `revealed_credential` prop, never in message text, and the page documents the no-store / `encryptHistory` requirement.
- **Dates:** use `lib/format.ts` (es-CO, America/Bogota). Countdowns use `useClock` with mock start `NOW_ISO`.
- **Accessibility:**
  - Labels on every control; 44 px targets.
  - The chat log uses `role="log"` with `aria-live="polite"`; the countdown is announced politely.
  - Focus management in dialogs and drawers.
  - No color-only meaning; the overdue review uses icon + text.
- **New code locations:**
  - Pure logic goes in `resources/js/lib/` or `Components/assistant/*.ts`, with Vitest tests written FIRST (RED → GREEN) and relative imports.
  - Page-local components go under `resources/js/Components/assistant/{area}/`.
  - Small additive fixes to shared components are allowed; list each one.

## Verification (foreground; report `<command>: <observed result>`)
- `npm run typecheck`
- `npm test` (all existing tests stay green)
- `npm run build:preview`
- Visual check in the gallery of every new page variant at 390 and 1440, light and dark. Use `npm run dev:preview` on a free port other than 5174, or the built `ui-preview/dist`. Fix any layout bugs you find.

## Return
- Status and files.
- The full route list (name, method, params/body) and permissions.
- The variants list.
- Verification results.
- Deviations and facts invented.

The ~400-line size heuristic is advisory, not a cap. Close with `## Key Learnings` (1–5 numbered standalone sentences).
