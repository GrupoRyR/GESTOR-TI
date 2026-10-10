# Stage C · Frontend contract for the app agent (Asistente TI + pending Stage B forms)

Builds on [stage-a-contract.md](stage-a-contract.md) and [stage-b-contract.md](stage-b-contract.md), with the same shared props, layouts and conventions. The approved mockups are on the design canvas, page "Etapa C · Asistente TI".

**The source of truth is the types files.** Each one starts with every route name it uses (method, params, body) and every permission:

| Types file | Pages (Inertia components) |
|---|---|
| `types/pages/assistant-chat.ts` | `Assistant/Chat` (one page: phone chat, tablet drawer, desktop with conversations and a source preview) |
| `types/pages/assistant-knowledge.ts` | `Assistant/Knowledge/Index`, `Assistant/Knowledge/Show`, `Assistant/Knowledge/Create`, `Assistant/Knowledge/Edit` |
| `types/pages/assistant-curation.ts` | `Assistant/Import/Create`, `Assistant/Import/Mapping`, `Assistant/Import/Preview`, `Assistant/Drafts/Index`, `Assistant/Unanswered/Index` |
| `types/pages/ai-knowledge.ts` (Stage B, extended) | `Ai/Products/Create`, `Ai/Products/Edit`, plus the incident drawer on `Ai/Products/Show` (`incident_form` prop) |
| `types/pages/ai-value-vault.ts` (Stage B, extended) | `Ai/Vault/Edit`, `Ai/Vault/Create` |

- **Domain types:** `types/assistant.ts`.
- **Example data:** `mocks/assistant-*.ts`, built from `mocks/assistant-fixtures.ts`. To see each page with its props, run `npm run dev:preview` and use "Ver props".
- **Telegram:** formats and buttons the backend must reproduce are in [telegram-format.md](telegram-format.md).

## Layout
`AssistantLayout` is AppLayout with the module `assistant` and these tabs:

| Tab | Route |
|---|---|
| Chat | `assistant.index` |
| Base de conocimiento | `assistant.knowledge.index` |
| Importar desde Excel | `assistant.import.create` |
| Borradores de /aprender | `assistant.drafts.index` |
| Preguntas sin respuesta | `assistant.unanswered.index` |

- The tab counts come from the page props.
- The management tabs need `assistant.knowledge.manage`. A user with only `assistant.use` sees no tab strip.
- AppLayout gained two optional props: `phoneHeader` (replaces the top bar below 768 px) and `fullHeight` (chat).

## Rules the backend must enforce (the UI only hides)

**Permissions**

| Permission | Gives | Today |
|---|---|---|
| `assistant.use` | The chat | Jhon and Leonardo |
| `assistant.knowledge.manage` | Cards (create, edit, delete, review), import, drafts, unanswered questions | Jhon and Leonardo |
| `assistant.credentials` **and** `credentials.view` | Password delivery | Only Jhon and Leonardo |

- Without `assistant.use` the chat shows the no-access state, listing `access_holders`.
- Without `assistant.knowledge.manage` the management pages render NoPermission.
- Without the credential permissions the server answers with a `refused` message.

**Passwords never pass through the AI provider**
- A `credential_notice` message never carries the value.
- `assistant.credentials.reveal` returns `revealed_credential {message_id, value, expires_in_seconds: 30}` exactly once.
- Respond with `Inertia::encryptHistory()` and no-store caching.
- Log the reveal and alert Jhon by Telegram. `assistant.credentials.copied` logs the copy.
- Vault items are never delivered through the chat: answer with a `vault_link` message to `ai.vault.index {item}`.

**Card visibility**
- Visibility is `it` ("Solo TI") or `company` ("Toda la empresa").
- Lists, card pages and answers include only the cards the person may see.

**What the AI provider may receive**
- It never receives credentials, vault content, rates, salaries or client data.
- It only receives the question and the cards or data the tools return.

**Assistant status**
- The chat page takes a `status`: `ready`, `unavailable` (provider error, shows "Reintentar"), `off_no_key` or `off_cap`.
- Only Súper Admins (`can_configure_ai`) see the reason and the link to `admin.integrations.index`.

**Chat flow**
- Each question is a normal Inertia POST to `assistant.messages.store`. The server returns the updated `conversation`: no streaming, no client fetch.
- Persist the user question before calling the AI. While the last message is an unanswered user question, the page shows "Buscando en la base de conocimiento…".
- "No lo tengo documentado" answers register the question in `assistant_unanswered`.

**`/aprender`**
- It is the only write the assistant makes, and it creates a draft pending approval.
- Approving needs a title and a company. Optional fields marked "Falta" (for example the owner) do not block approval.
- Discarding requires a reason.

**Import**
- The upload is multipart with `forceFormData`.
- Mapping and preview are server-side steps. Rows with errors are skipped, and their file is downloadable from `assistant.import.errors`.

**Vault edit**
- It needs `ai.vault.access` and an open vault session; otherwise redirect to the locked `ai.vault.index {item}`.
- The form never receives the current value. An empty `value` on update keeps it.
- Delete requires typing the exact name.
- Every change is audited and alerted by Telegram.
- Respond with `encryptHistory()` and no-store.

**Products and incidents**
- Products need `ai.manage`. There is no delete: a product leaves service with the state "Retirado".
- Incidents are registered by hand while monitoring is not active.
  - The drawer posts to `ai.products.incidents.store {product}`.
  - On validation errors, render Show with `errors` and `incident_form.open = true`.
  - The GET `ai.products.incidents.create {product}` stays as a deep link that opens the drawer.

## Route names added by design (confirm or rename)
- **Chat:**
  - `assistant.messages.retry` (POST `{message}`)
  - `assistant.credentials.copied` (POST `{message}`)
- **Import:**
  - `assistant.import.update` (PUT `{import}`, sheet and header row)
  - `assistant.import.destroy` (DELETE `{import}`)
- **Vault:** `ai.vault.create` / `ai.vault.store`, which reuse the edit form for "Nueva credencial".

If a name changes, update the types file header and the `route()` calls in the page.

## Partial reloads used (Inertia v2)

| Page | `only` | Trigger |
|---|---|---|
| Chat | `['selected_source']` | Query `?fuente=K-031` |
| Chat | `['revealed_credential']` | Reveal |
| Chat | `['conversation']` | Copied |
| Import preview | `['rows','filter']` | Query `?resultado=new\|update\|error` |
| Drafts | `['selected','requested']` | Query `?borrador=` (and `&desde=telegram`) |

Knowledge index filters are a GET query with `preserveState`: `?q=&tipo=&empresa=&area=&visibilidad=&vencidas=1&page=`.

## Open points for Jhon or the app agent
- **Vault rotation reminder:** the edit form says the reminder arrives 7 days before rotation, while the vault index flags items from 30 days. Keep both (visual flag at 30, Telegram reminder at 7) or align them.
- **Product area:** it is a single select; products used by several areas would need a multi-select.
- **Placeholders still open:**
  - `[NOMBRE DEL BOT]`
  - `[TOPE MENSUAL]`
  - `[TAMAÑO]` (import limit)
  - `[FRECUENCIA]` and `[VERSIÓN]` in sample cards
  - `[RUTA EN SAP]`: the assistant never invents menu paths.
- **Sample data:** the mock cards, the import file and the drafts are example data.
