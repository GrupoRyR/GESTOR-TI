/**
 * Administración · Costos y aprobaciones (board B-AdminRates). Desktop-first.
 *
 * Inertia component and props:
 * - `Admin/Costs/Index`  AdminCostsIndexProps  rates, area hour values, project approvers, budget alerts
 *
 * Ziggy routes used:
 * - `admin.costs.index`                GET    —
 * - `admin.costs.rates.update`         PUT    `{ user: id }` body `RatePayload`       "Cambiar tarifa" dialog
 * - `admin.costs.area-values.update`   PUT    body `{ values: { area_id: number, value: number | null }[] }`  "Editar valores"
 * - `admin.costs.approvers.store`      POST   body `{ user_id: number }`              "Agregar aprobador"
 * - `admin.costs.approvers.destroy`    DELETE `{ user: id }`                          remove an approver (confirmed)
 * - `admin.costs.settings.update`      PUT    body `CostSettingsPayload`              "Guardar cambios"
 *
 * Permissions:
 * - `ai.costs.view`    needed for the whole page (the tab only shows with it). Without it the server sends
 *                      `rates: null` and `area_values: null` and the page renders NoPermission.
 * - `ai.costs.manage`  edit rates and area hour values; read-only otherwise.
 * - `ai.manage`        edit approvers and the settings; read-only otherwise.
 * - Approvers are the holders of `ai.projects.approve` (role "Aprobador de proyectos"): adding or removing one
 *   grants or revokes that role.
 */
import type { AreaRef, PersonRef } from '@/types/ai';

export interface RateChange {
    /** COP per hour. */
    rate: number;
    effective_from: string;
    changed_by: string | null;
}

export interface RateRow {
    person: PersonRef;
    /** Current internal cost per hour (COP). */
    rate: number;
    effective_from: string;
    /** Previous values, newest first (the current one excluded). */
    history: RateChange[];
}

export interface AreaValueRow {
    area: AreaRef;
    /** COP an hour of that area is worth; null = not set (no payback is computed). */
    value: number | null;
}

export interface ApproverRow {
    person: PersonRef;
    /** "Líder IA · Segurex". */
    detail: string;
}

export interface CostSettings {
    /** "Si el aprobador es el mismo que evaluó, pedir la aprobación del otro". */
    require_other_approver: boolean;
    /** Re-approval threshold in percent (20). The 80 % and 100 % alerts are fixed. */
    reapproval_threshold_percent: number;
}

export interface RatePayload {
    rate: number | null;
    effective_from: string;
}

export type CostSettingsPayload = CostSettings;

export interface AdminCostsIndexProps {
    /** Null without `ai.costs.view`. */
    rates: RateRow[] | null;
    /** Null without `ai.costs.view`. */
    area_values: AreaValueRow[] | null;
    approvers: ApproverRow[];
    /** IT and management users who could become approvers. */
    approver_candidates: PersonRef[];
    settings: CostSettings;
    /** Bogotá date (YYYY-MM-DD), default "Vigente desde" in the rate dialog. */
    today: string;
}
