# Stage C · Asistente TI (backend) + pending Stage B forms

Source of truth: `PROMPT_GESTOR_IA_APP.md` §5 (6.8–6.8.4), §8. Page contracts (design PR GrupoRyR/GESTOR-TI#11, merged into this chain as `747177d`):
`docs/design/stage-c-contract.md`, `docs/design/telegram-format.md`, `resources/js/types/assistant.ts`,
`types/pages/assistant-{chat,knowledge,curation}.ts`, extended `types/pages/ai-knowledge.ts` and `ai-value-vault.ts`.

## Objective
The Asistente TI answers in the app and by Telegram from a knowledge base that lives in the Gestor TI, citing the cards it used,
with read-only tools, `/aprender` drafts, unanswered questions, Excel import, and password delivery that never passes through the AI provider.
Plus the three Stage B forms that had no design: product create/edit, incident registration, vault item create/edit.

## Decisions
- 2026-10-10 (orchestrator, per user request "confirma o renombra"): the route names added by design are confirmed as-is:
  `assistant.messages.retry`, `assistant.credentials.copied`, `assistant.import.update`, `assistant.import.destroy`, `ai.vault.create`/`ai.vault.store`.
- Vault reminders: keep both — visual flag on the index from 30 days, notifications at 30, 15 and 7 days (Stage B behaviour).
- Product area: the form's single select sets the primary area; extra areas stay in the pivot (B2 model is multi-area).
- AI provider: DeepSeek through the Laravel AI SDK (`laravel/ai`), synchronous `prompt()`, max 5 tool rounds, 60 s timeout, one retry on 429/5xx; app works without a key (status `off_no_key`) or over the cap (`off_cap`).
- Search: MySQL FULLTEXT + glossary synonyms on the server; LIKE/keyword fallback on SQLite (tests).

## Delivery
- Branch `claude/gestor-ia-app-prompt-d14b73`; slices `stage-c/NN-*` stacked on `stage-b/08-value-vault`. RDD off.
- Checks: `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm test`, `npm run build`.

## Tasks
- [x] C0 · Merge design PR #11 (`747177d`): no conflicts; typecheck, 393 vitest and build green.
- [ ] C1 · Knowledge base: `knowledge_items` (types system/report/flow/procedure/glossary per design, companies, area, tags, owner, visibility it|company, reviewed_at, is_current, FULLTEXT), `knowledge_links`, glossary synonyms, search service with visibility; pages `Assistant/Knowledge/{Index,Show,Create,Edit}`; 6-month review reminder.
- [ ] C2 · Curation: Excel import (upload, mapping, preview, apply, errors file), `/aprender` drafts (create, approve with title+company, discard with reason), unanswered questions; pages `Assistant/Import/*`, `Assistant/Drafts/Index`, `Assistant/Unanswered/Index`.
- [ ] C3 · Assistant engine + chat: `laravel/ai` agent with DeepSeek, tool catalog (`buscar_conocimiento`, `ver_ficha`, `buscar_productos`, `ver_producto`, `buscar_solicitudes_gestor_ia`, `entregar_credencial`), conversations (30 min context), `ai_interactions` logging with cached tokens + cost, monthly cap 80/100 %, statuses; page `Assistant/Chat` (messages store/retry, sources, credential notice + reveal + copied, vault links).
- [ ] C4 · Telegram assistant: questions via the bot (typing, after-response), `/aprender`, credential delivery as a separate message deleted after 60 s, formats and buttons per `telegram-format.md`.
- [ ] C5 · Pending Stage B forms: `Ai/Products/Create|Edit`, incident drawer (`ai.products.incidents.store`), `Ai/Vault/Create|Edit` (+ store/update/destroy with typed-name confirmation). Remove their TEMPORARY routes.

## Progress / evidence

## Next step
C1.
