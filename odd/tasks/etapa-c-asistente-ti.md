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
- [x] C1 · Knowledge base: `knowledge_items` (types system/report/flow/procedure/glossary per design, companies, area, tags, owner, visibility it|company, reviewed_at, is_current, FULLTEXT), `knowledge_links`, glossary synonyms, search service with visibility; pages `Assistant/Knowledge/{Index,Show,Create,Edit}`; 6-month review reminder. Route: delegated (writer trigger: 2+ non-trivial files).
- [ ] C2 · Curation: Excel import (upload, mapping, preview, apply, errors file), `/aprender` drafts (create, approve with title+company, discard with reason), unanswered questions; pages `Assistant/Import/*`, `Assistant/Drafts/Index`, `Assistant/Unanswered/Index`.
- [ ] C3 · Assistant engine + chat: `laravel/ai` agent with DeepSeek, tool catalog (`buscar_conocimiento`, `ver_ficha`, `buscar_productos`, `ver_producto`, `buscar_solicitudes_gestor_ia`, `entregar_credencial`), conversations (30 min context), `ai_interactions` logging with cached tokens + cost, monthly cap 80/100 %, statuses; page `Assistant/Chat` (messages store/retry, sources, credential notice + reveal + copied, vault links).
- [ ] C4 · Telegram assistant: questions via the bot (typing, after-response), `/aprender`, credential delivery as a separate message deleted after 60 s, formats and buttons per `telegram-format.md`.
- [ ] C5 · Pending Stage B forms: `Ai/Products/Create|Edit`, incident drawer (`ai.products.incidents.store`), `Ai/Vault/Create|Edit` (+ store/update/destroy with typed-name confirmation). Remove their TEMPORARY routes.

## Progress / evidence

### C1 · Knowledge base
- Schema `2026_10_22_000001_create_knowledge_tables`: `knowledge_items` (key `K-NNN` from `Sequence` `knowledge_items`, type, title, description, `fields` json, `search_text`, `tags` json, visibility, area, owner, `review_at`, last review, `is_current`, `source` manual|import|learn, created/updated by, soft deletes; MySQL FULLTEXT on title+description+search_text), pivot `knowledge_item_company`, `knowledge_links` (card → card or Gestor IA product, ordered).
- `App\Domain\Assistant\{KnowledgeTypes, KnowledgeFilters, KnowledgeSearch, Synonyms}`, `App\Actions\Assistant\KnowledgeItems` (create/update/review/delete + activity log `knowledge`), `KnowledgeItemPolicy`, `KnowledgeItemRequest`, `KnowledgePresenter`, `AssistantTabCounts`, `Assistant\KnowledgeController`, `Assistant\StageCPlaceholderController` (TEMPORARY `assistant.import.create`, `assistant.drafts.index`, `assistant.unanswered.index` until C2).
- Review reminder: `assistant:knowledge-review-reminders`, Mondays 08:00 Bogotá, one digest per manager (`KnowledgeReviewReminder`, event `assistant.knowledge.review`).
- `AssistantDemoSeeder` (not in DatabaseSeeder): 6 sample cards like the mocks, placeholders kept.
- Tests (RED observed first: 29 tests, 27 errors + 2 failures before implementation): `tests/Feature/Assistant/{KnowledgePagesTest, KnowledgeWriteTest, KnowledgeSearchServiceTest, KnowledgeReviewRemindersTest, AssistantDemoSeederTest}`.
- Checks: `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit` 821 passed (8317 assertions); plain `php artisan test` (PHP default 128M) dies with "Allowed memory size exhausted" in `ErrorPagesTest` near the end of the run (the test passes alone): the suite needs a higher memory limit (`<ini name="memory_limit" value="512M"/>` in phpunit.xml, outside C1's edit surface, pending decision). `vendor/bin/pint --test` pass; `npm run typecheck` pass; `route:list --path=assistant` 12 routes; `schedule:list` shows `0 8 * * 1 assistant:knowledge-review-reminders`; migrate up/rollback/up on a temp SQLite file OK.

Decisions (C1):
- Design types win over the brief: types `system|report|data_flow|procedure|glossary`, fields per `lib/knowledgeFields.ts`, `description`, `review_at` = next review (6 months after the last one).
- Visibility: "Solo TI" only for `assistant.knowledge.manage` holders (contract: "only knowledge managers"; `ai.manage` alone does not grant it). Everybody sees only cards of their company context (CurrentCompany; `companies.view_all` on "Todas" sees all). Hidden cards answer 404.
- Index/Show need `assistant.use` or `assistant.knowledge.manage` (policy); Create/Edit/writes need `can:assistant.knowledge.manage` (403; the design Create/Edit pages have no NoPermission state).
- The `q` filter keeps the contract order (last edited first); `KnowledgeSearch::search()` ranks by relevance for the assistant tool (C3).
- Synonyms come from glossary cards (`term` + `also_called`), no table; a query containing every word of one phrase also searches the other phrases.
- Gestor IA products are not copied into cards: they are "Relacionadas" links and the C3 tool `buscar_productos` searches them.
- Secret guard: `SecretGuard::find()` on title, description and every field; Spanish message per field.
- No `product_id` column: the link table covers system ↔ product.

Notes for design (no visual change needed):
- `usage_count_month`, `last_cited_at`, `last_cited_by` are 0/null until C3 logs citations; non-managers get `recent_changes: []`.
- `prefill` is always null until C2 (`?pregunta=`, `?borrador=`); `unanswered_count`/`drafts_count` are 0 until C2.
- `owner_options` lists active users; groups ("Gerencia de cartera") must be users today.

## Next step
C2 (curation: Excel import, `/aprender` drafts, unanswered questions; replace the TEMPORARY tab routes).
