/**
 * Gestor IA · value dashboard and vault (boards B21–B22). The dashboard is
 * desktop-first with a phone layout (B-ValuePhone); the vault is phone-ready
 * (B-VaultPhone) and always renders inside the dark protected zone.
 *
 * Inertia components and their props:
 * - `Ai/Value/Index`   ValueIndexProps   B-Value + B-ValuePhone (GestorLayout tab "value"); empty state per B-States
 * - `Ai/Vault/Index`   VaultIndexProps   B-VaultLocked + B-VaultOpen + B-VaultPhone (tab "vault")
 * - `Ai/Vault/Denied`  VaultDeniedProps  B-VaultDenied (anyone without `ai.vault.access` who opens the vault URL)
 *
 * Ziggy routes used:
 * - `ai.value.index`     GET   query `{ period?: ValuePeriod, company_id?, area_id? }` filters via `router.get`
 *                                (preserveState, preserveScroll; defaults omitted: period 6m, all companies and areas)
 * - `ai.value.export`    GET   same query                          "Descargar para la junta" (PDF)
 * - `ai.ratings.remind`  POST  `{ request: key }`                   empty state "Recordar a …" (asks the requester to rate)
 * - `ai.products.show`   GET   `{ product: slug }`                  phone "Productos que más ahorran" rows
 * - `ai.vault.index`     GET   query `{ item?: id }`                tab; choosing an item is a partial reload
 *                                `only: ['selected', 'access_log', 'revealed']`
 * - `ai.vault.reauth`    GET   query `{ item?: id }`                "Confirmar identidad con Microsoft": FULL-PAGE redirect
 *                                (plain `<a>`, not an Inertia visit); back to `ai.vault.index` with the session open
 * - `ai.vault.lock`      POST  —                                    "Cerrar bóveda"
 * - `ai.vault.reveal`    POST  `{ item: id }`                       "Ver valor": responds with the page where `revealed`
 *                                carries the value (partial `only: ['revealed', 'access_log']`). The response must use
 *                                `Inertia::encryptHistory()` and no-store caching; the UI wipes the prop after 30 s.
 * - `ai.vault.copied`    POST  `{ item: id }`                       after "Copiar" (logs the copy and alerts by Telegram;
 *                                partial `only: ['access_log']`). The value itself is never sent back.
 * - `ai.vault.edit`      GET   `{ item: id }`                       "Cambiar valor" (PENDING: form not designed yet)
 * - `ai.index`           GET   —                                    Denied: "Volver al Gestor IA"
 * - `assistant.index`    GET   —                                    Denied: ask for a credential through the Asistente
 *
 * Permissions:
 * - `ai.manage`        Value dashboard.
 * - `ai.costs.view`    `ValueMetrics.monthly_cost`, `roi`, `hour_value` (null without it): the cost tile shows a lock
 *                      hint and the payback block becomes a lock note.
 * - `ai.vault.access`  the vault (only Jhon Castro; never a role permission). Without it the server renders
 *                      `Ai/Vault/Denied`, logs a `blocked` access and alerts the holder by Telegram.
 * - Secrets: `VaultItem` never carries a value. Only `revealed` does, one item at a time, after `ai.vault.reauth`.
 *   The UI never puts it in titles, aria-labels, logs or URLs, and clears it at 30 s or on unmount.
 */
import type { ValuePeriod } from '@/Components/ai/value/value';
import type { VaultSessionState } from '@/Components/ai/vault/vault';
import type { AreaRef, PersonRef, ValueMetrics, VaultAccessLog, VaultItem, VaultRevealed } from '@/types/ai';

export type { ValuePeriod, VaultSessionState };

// ---- Value --------------------------------------------------------------------

export interface ValueFilters {
    period: ValuePeriod;
    /** Only used in "Todas". */
    company_id: number | null;
    area_id: number | null;
}

/** Product row for the phone list "Productos que más ahorran". */
export interface ValueProduct {
    slug: string;
    name: string;
    company_id: number;
    hours_per_month: number;
    /** Null without `ai.costs.view`. */
    payback_months: number | null;
}

export interface ValueIndexProps {
    /** Null when nothing has been rated yet in the period (empty state). */
    metrics: ValueMetrics | null;
    filters: ValueFilters;
    areas: Pick<AreaRef, 'id' | 'name' | 'company_id'>[];
    /** Products in production with saved hours, most hours first. */
    products: ValueProduct[];
    /** Short readings under "Horas ahorradas por área". */
    area_notes: string[];
    /** "Cámaras no aparece: aún no está en producción". */
    roi_note: string | null;
    /** Delivery waiting for its first rating (empty state CTA); null when none. */
    awaiting_rating: { request_key: string; title: string; requester: PersonRef } | null;
    /** "Hosting, bases de datos y dominios de 5 productos"; null without `ai.costs.view`. */
    cost_note: string | null;
    /** Optional caveat under the dashboard (the mocks use it to mark sample figures). */
    data_note: string | null;
    now: string;
}

// ---- Vault --------------------------------------------------------------------

export interface VaultIndexProps {
    /** Server-side session: `open` after `ai.vault.reauth`, locks itself after 10 min without use. */
    session: VaultSessionState;
    /** Metadata only, every item (also while locked). */
    items: VaultItem[];
    /** Item of `?item=`; null when none is chosen. */
    selected: VaultItem | null;
    /** The only secret-carrying prop: present right after `ai.vault.reveal`, null otherwise. */
    revealed: VaultRevealed | null;
    /** Accesses of the selected item, newest first (empty while locked). */
    access_log: VaultAccessLog[];
    /** Each access alerts the holder by Telegram (shown as a note). */
    telegram_alerts: boolean;
    /** Holder of `ai.vault.access` ("Jhon Castro"). */
    holder_name: string;
    /** Bogotá date (YYYY-MM-DD). */
    today: string;
    now: string;
}

export interface VaultDeniedProps {
    holder_name: string;
    /** The blocked attempt that was just logged. */
    attempt: { person: PersonRef; at: string };
}
