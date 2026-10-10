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
- [x] C2 · Curation: Excel import (upload, mapping, preview, apply, errors file), `/aprender` drafts (create, approve with title+company, discard with reason), unanswered questions; pages `Assistant/Import/*`, `Assistant/Drafts/Index`, `Assistant/Unanswered/Index`. Route: delegated (writer trigger: 2+ non-trivial files).
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

### C2 · Curation
- Schema `2026_10_23_000001_create_assistant_curation_tables`: `knowledge_imports` (user, original name, private disk + path, size, status uploaded|mapped|previewed|applied|failed, sheets read on upload, sheet, header_row, sheet type, mapping, stats, error file path, error message, applied_at), `knowledge_drafts` (raw message, source app|telegram, requested_by, proposed json, missing keys, status pending|approved|merged|discarded, discard reason, card, decided_by/at, Telegram chat + message ids for C4), `assistant_unanswered` (question, normalized hash, times_asked, first/last asked, status open|documented|dismissed, card, resolved_by/at) + `assistant_unanswered_asks` (who, channel app|telegram, conversation/message ids for C3/C4, asked_at).
- Import: `openspout/openspout` ^5.3 (pure PHP, needs only zip + xml). `App\Domain\Assistant\Import\{Spreadsheet, ImportFields, ImportAnalyzer, ImportRow}`, `App\Actions\Assistant\KnowledgeImports`, `Assistant\ImportController`, `ImportUploadRequest`, `ImportMappingRequest`. Routes `assistant.import.{create,template,upload,update,destroy,mapping,mapping.update,preview,errors,store}`.
- Drafts: `App\Domain\Assistant\{LearnDrafts::fromText, DraftParser (interface, #[Bind] HeuristicDraftParser), HeuristicDraftParser, DraftRejected, CompanyNames}`, `App\Actions\Assistant\DraftDecisions` (approve/merge/approvedWith/discard), `Assistant\DraftController`. Routes `assistant.drafts.{index,approve,merge,discard}`.
- Unanswered: `App\Domain\Assistant\Unanswered::record()` (for C3/C4), `document()`, `dismiss()`; `Assistant\UnansweredController`. Routes `assistant.unanswered.{index,link,discard}`.
- `KnowledgeController::create` sends `prefill` for `?borrador=` (pending draft) and `?pregunta=` (open question); `store` with `draft_id` approves the draft (card source `learn`) and with `unanswered_id` documents the question (`KnowledgeItemRequest` validates both as still pending). `AssistantTabCounts` counts pending drafts and open questions. `CurationPresenter` (`ImportFile`, `LearnDraft`, `UnansweredQuestion`). TEMPORARY `StageCPlaceholderController` removed.
- Tests (RED observed first: 30 failing — 27 errors + 3 failures — of 60 in `tests/Feature/Assistant`): `tests/Feature/Assistant/{CurationTestCase, ImportTest, LearnDraftsTest, UnansweredTest}` (real .xlsx generated with OpenSpout + a `;` CSV with BOM, `Storage::fake('local')`), `KnowledgePagesTest` tab-route test updated (no more placeholder redirect).
- Checks: RED observed (30 of 60 assistant tests failing before the code); `php artisan test` 850 passed (8988 assertions); `vendor/bin/pint --test` pass; `npm run typecheck` pass; `route:list --path=assistant` 26 routes, no placeholders; migrate up/rollback/up on a temp SQLite file OK. Commit `3895e33`.

Decisions (C2):
- Reader: OpenSpout cannot read the binary `.xls`, so only `.xlsx` and `.csv` are accepted; a `.xls` gets "Solo se pueden importar archivos .xlsx o .csv. Si tienes un .xls, ábrelo en Excel y guárdalo como .xlsx." CSV: `;` or `,` sniffed from line 1, UTF-8 (BOM ok) else Windows-1252; its sheet is named after the file.
- Limits live in `config/gestor.php` → `gestor.assistant.import` (`disk` local, `max_kb` 10240 from `ASSISTANT_IMPORT_MAX_KB`, `max_rows` 2000 per sheet, `preview_new_rows` 20): `config/assistant.php` was outside the edit surface. `ASSISTANT_IMPORT_MAX_KB` is not in `.env.example` (file not editable here). `max_size_label` = "10 MB" replaces the `[TAMAÑO]` placeholder.
- One unfinished import per person: a new upload deletes the previous unfinished one (record + files). Imports are private to the uploader (others: 404). An applied import cannot be reused (redirect to step 1).
- Sheet type: majority of a "Tipo" column, else the sheet name ("Informes", "Glosario"…), else Informe. A row whose "Tipo" names another type is an error.
- Update matching (documented): a "Clave" column (`key`, server-added optional field "Clave de la ficha (K-031)") updates that exact card (must exist, be visible and of the sheet type); otherwise a visible card of the same type and the same normalized title; else new. An empty cell never erases a card value. A second row for the same card or title is an error.
- Row checks: title (≤160), company (names/short names/slug/distinctive words, several with `,` `;` `/` `y`, "Todas"; only companies the person may act on), area of one of those companies, visibility (Solo TI / Toda la empresa), owner = active user by name or email (`[DUEÑO]` placeholder = empty), 5,000 chars per cell, SecretGuard. Type fields are all optional (design rule), so imported or approved cards may lack a field the form requires; the form asks for it on the next edit.
- Apply: creates/updates through `KnowledgeItems` (source `import`, activity log `knowledge` per card plus one `imported` entry), skips error rows, deletes the original file, keeps the errors file until "Quitar"; flash "Se importaron N fichas: X nuevas y Y actualizadas. La fila 17 se saltó." With nothing importable: flash error on the preview.
- Errors file: `.xlsx` with `Fila`, the sheet columns, `Error`, `Cómo corregirlo` (e.g. "En la celda C17 escribe Segurex, Derivados o Tercera (varias, separadas por coma)…").
- `/aprender`: `LearnDrafts::fromText(User, text, source, chatId?, messageId?)` refuses empty, >2,000 chars or secrets (`DraftRejected`, Spanish message). Heuristic pre-fill (no AI): type by keywords, title = first sentence (≤80), description = text, companies named in the text else the author's (assumed), area named else the author's (assumed), visibility `it` until approved, report source/schedule/recipients from "lo envía X a las 6 a. m. a gerencia". C3 binds an AI `DraftParser`.
- Channel values follow the contract `LearnChannel` (`app` | `telegram`), not "web". Draft status adds `merged` ("Agregar a K-031": fills empty fields of a same-type card and appends the text to its description).
- Approve needs title + a company the approver may act on (flash error and `?borrador=` otherwise); "Falta" fields never block. Decided drafts answer "Este borrador ya se decidió." (Telegram may decide first).
- Unanswered grouping: same normalized text (accents, case, punctuation, spaces) on the open row; a documented or dismissed question asked again opens a new row. Not split by company (managers are global today); drafts neither.
- The three tab pages need `assistant.use` or `assistant.knowledge.manage` (403 otherwise) and render NoPermission with empty keys without manage; every other curation route is `can:assistant.knowledge.manage`.

Notes for design (no visual change needed):
- Mapping `fields` has one more optional field, `key` ("Clave de la ficha (K-031)"), after the common ones; `lib/importMapping.ts` does not know it (client `autoMap` would leave a "Clave" column unmapped until the server mapping arrives, which the page already uses).
- Draft field labels come from the card form (`Quién lo envía · sistema origen`, `Destinatarios`, `Horario`), not the mock wording (`Sistema de origen`, `Quién lo recibe`, `Horario de envío`). Import change labels use the import wording.
- Approve blocked by title/company and "nothing to import" are flash errors, not field errors.
- The template has one sheet per type with headers `Título, Tipo, Empresa, Área, Descripción, Visibilidad, Dueño` + the type fields.

## Next step
C3 (assistant engine + chat: `laravel/ai` agent, tools, conversations, `ai_interactions`, `Assistant/Chat`); it calls `Unanswered::record()` on "No lo tengo documentado" and may bind an AI `DraftParser`.
