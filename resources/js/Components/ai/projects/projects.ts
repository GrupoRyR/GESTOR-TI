/**
 * Pure helpers for the project pages (summary, board, requirements, time and
 * costs, report): calendar math on plain `YYYY-MM-DD` dates, hour totals,
 * the end-date projection, budget wording, requirement states, report
 * recipients and optimistic Kanban moves. Pass `today` for stable output.
 */
import { DEFAULT_REAPPROVAL_THRESHOLD, budgetRatio, daysLate, needsReapproval, scheduleNeedsReapproval, WARNING_RATIO } from '../../../lib/budget';
import { formatCOP, formatShortDate } from '../../../lib/format';

const DAY = 86_400_000;

/** Day number of a date or date-time, using its written date part. */
function dayNumber(iso: string): number {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    if (!match) throw new RangeError(`Invalid ISO date: ${iso}`);
    return Math.round(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])) / DAY);
}

function fromDayNumber(day: number): string {
    return new Date(day * DAY).toISOString().slice(0, 10);
}

const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });
const days = (count: number) => `${count} ${count === 1 ? 'día' : 'días'}`;

// ---- dates ------------------------------------------------------------------

/** `YYYY-MM-DD` plus `count` calendar days. */
export function addDays(date: string, count: number): string {
    return fromDayNumber(dayNumber(date) + count);
}

export interface DateRange {
    from: string;
    to: string;
}

/** Monday–Sunday week that holds `date`. */
export function weekBounds(date: string): DateRange {
    const day = dayNumber(date);
    // 1970-01-01 was a Thursday: (day + 3) % 7 is 0 on Mondays.
    const fromMonday = (((day + 3) % 7) + 7) % 7;
    return { from: fromDayNumber(day - fromMonday), to: fromDayNumber(day - fromMonday + 6) };
}

/** "Semana del 5 al 11 oct" · "Semana del 28 sep al 4 oct". */
export function weekLabel({ from, to }: DateRange): string {
    const [fromDay, fromMonth] = formatShortDate(from).split(' ');
    const sameMonth = from.slice(0, 7) === to.slice(0, 7);
    return `Semana del ${sameMonth ? fromDay : `${fromDay} ${fromMonth}`} al ${formatShortDate(to)}`;
}

export type EntryDateChoice = 'today' | 'yesterday' | 'other';

/** Date of a manual time entry; null while "Otra fecha" has no date. */
export function entryDate(choice: EntryDateChoice, today: string, other: string | null): string | null {
    if (choice === 'today') return today;
    if (choice === 'yesterday') return addDays(today, -1);
    return other;
}

// ---- hours ------------------------------------------------------------------

interface PersonLike {
    id: number;
    name: string;
}

/** Hours per person (optionally inside `range`), most hours first. */
export function hoursByPerson<P extends PersonLike>(
    entries: readonly { date: string; person: P; hours: number }[],
    range?: DateRange,
): { person: P; hours: number }[] {
    const totals = new Map<number, { person: P; hours: number }>();
    for (const entry of entries) {
        if (range && (entry.date < range.from || entry.date > range.to)) continue;
        const current = totals.get(entry.person.id) ?? { person: entry.person, hours: 0 };
        current.hours = Number((current.hours + entry.hours).toFixed(2));
        totals.set(entry.person.id, current);
    }
    return [...totals.values()].sort((a, b) => b.hours - a.hours);
}

/** Average hours per week over the last `weeks`. */
export function burnRate(recentHours: number, weeks: number): number {
    return weeks > 0 ? recentHours / weeks : 0;
}

/** Day the remaining hours run out at the weekly pace; null when nothing is being logged. */
export function projectedEnd(today: string, remainingHours: number, hoursPerWeek: number): string | null {
    if (remainingHours <= 0) return today;
    if (hoursPerWeek <= 0) return null;
    return addDays(today, Math.ceil((remainingHours / hoursPerWeek) * 7));
}

// ---- budget -----------------------------------------------------------------

export interface BudgetInput {
    hours: { estimate: number; actual: number };
    cost: { estimate: number; actual: number } | null;
    due_date: string;
    projected_due_date: string;
    approved_at: string;
}

export type ReapprovalReason = 'hours' | 'cost' | 'date';

/** What went above the approval by more than `threshold` (lib/budget rules). Cost only counts when visible. */
export function reapprovalReasons(budget: BudgetInput, threshold = DEFAULT_REAPPROVAL_THRESHOLD): ReapprovalReason[] {
    const reasons: ReapprovalReason[] = [];
    if (needsReapproval(budget.hours.actual, budget.hours.estimate, threshold)) reasons.push('hours');
    if (budget.cost && needsReapproval(budget.cost.actual, budget.cost.estimate, threshold)) reasons.push('cost');
    if (scheduleNeedsReapproval(budget.approved_at, budget.due_date, budget.projected_due_date, threshold)) reasons.push('date');
    return reasons;
}

const REASON_WORDS: Record<ReapprovalReason, string> = { hours: 'las horas', cost: 'el costo', date: 'la fecha' };

/** "Lo real pasa el 20 % de lo aprobado en la fecha: pide nueva aprobación". */
export function reapprovalSentence(reasons: ReapprovalReason[], threshold = DEFAULT_REAPPROVAL_THRESHOLD): string {
    const words = reasons.map((reason) => REASON_WORDS[reason]);
    const list = words.length <= 1 ? (words[0] ?? 'el presupuesto') : `${words.slice(0, -1).join(', ')} y ${words[words.length - 1]}`;
    return `Lo real pasa el ${Math.round(threshold * 100)} % de lo aprobado en ${list}: pide nueva aprobación`;
}

export interface BudgetLine {
    value: string;
    message: string;
}

/** Figures and the line under each estimate-vs-actual bar. `cost` is null without `ai.costs.view`. */
export function budgetLines(budget: BudgetInput): { hours: BudgetLine; cost: BudgetLine | null; date: BudgetLine & { late: number } } {
    const { hours, cost } = budget;
    const hoursLeft = hours.estimate - hours.actual;
    const late = daysLate(budget.projected_due_date, budget.due_date);

    let costLine: BudgetLine | null = null;
    if (cost) {
        const ratio = budgetRatio(cost.actual, cost.estimate);
        const message =
            ratio >= 1
                ? 'Pasó el 100 %: aviso enviado a los aprobadores'
                : ratio >= WARNING_RATIO
                  ? 'Pasó el 80 %: aviso enviado a los aprobadores'
                  : `Quedan ${formatCOP(cost.estimate - cost.actual)}`;
        costLine = { value: `${formatCOP(cost.actual)} de ${formatCOP(cost.estimate)}`, message };
    }

    return {
        hours: {
            value: `${number(hours.actual)} de ${number(hours.estimate)} h`,
            message: hoursLeft >= 0 ? `Quedan ${number(hoursLeft)} h` : `Pasó por ${number(-hoursLeft)} h`,
        },
        cost: costLine,
        date: {
            value: `${formatShortDate(budget.projected_due_date)} · estimada ${formatShortDate(budget.due_date)}`,
            message: late > 0 ? `${days(late)} después de lo aprobado` : 'A tiempo',
            late,
        },
    };
}

export function costsTotal(costs: readonly { amount: number }[]): number {
    return costs.reduce((sum, cost) => sum + cost.amount, 0);
}

/** "3 de 3 en desarrollo". */
export function wipText(wip: { in_development: number; limit: number }): string {
    return `${wip.in_development} de ${wip.limit} en desarrollo`;
}

// ---- requirements -----------------------------------------------------------

export type RequirementLevel = 'pending' | 'overdue' | 'answered';

interface RequirementLike {
    status: 'pending' | 'overdue' | 'answered';
    due_date: string;
    answered_at: string | null;
}

/** Row state: "en 2 días", "hoy", "hace 6 días", "respondido el 5 oct"; `days` = due − today. */
export function requirementState(requirement: RequirementLike, today: string): { level: RequirementLevel; label: string; days: number } {
    const left = dayNumber(requirement.due_date) - dayNumber(today);
    if (requirement.status === 'answered') {
        return { level: 'answered', label: `respondido el ${formatShortDate(requirement.answered_at ?? requirement.due_date)}`, days: left };
    }
    if (requirement.status === 'overdue' || left < 0) return { level: 'overdue', label: `hace ${days(-left)}`, days: left };
    if (left === 0) return { level: 'pending', label: 'hoy', days: 0 };
    if (left === 1) return { level: 'pending', label: 'mañana', days: 1 };
    return { level: 'pending', label: `en ${days(left)}`, days: left };
}

/** For the person who must answer: "Faltan 2 días", "Vence hoy", "Venció hace 3 días". */
export function respondDeadline(requirement: RequirementLike, today: string): string {
    const left = dayNumber(requirement.due_date) - dayNumber(today);
    if (left < 0) return `Venció hace ${days(-left)}`;
    if (left === 0) return 'Vence hoy';
    return left === 1 ? 'Falta 1 día' : `Faltan ${left} días`;
}

// ---- report -----------------------------------------------------------------

export type ReportVersion = 'internal' | 'requester';

interface RecipientLike {
    person: PersonLike;
    can_view_costs: boolean;
    include_costs: boolean;
}

/** The per-recipient "Tiempo y costo" checkbox. */
export function recipientCosts(recipient: RecipientLike, _version: ReportVersion): { disabled: boolean; checked: boolean; reason: string | null } {
    if (!recipient.can_view_costs) {
        return { disabled: true, checked: false, reason: 'No tiene permiso de costos: recibe la versión sin tiempo ni costo.' };
    }
    return { disabled: false, checked: recipient.include_costs, reason: null };
}

/** Who receives each version: the internal one only with cost permission and the box checked. */
export function recipientsOf<R extends RecipientLike>(recipients: readonly R[], version: ReportVersion): R[] {
    const internal = (recipient: R) => recipient.can_view_costs && recipient.include_costs;
    return recipients.filter((recipient) => (version === 'internal' ? internal(recipient) : !internal(recipient)));
}

// ---- board ------------------------------------------------------------------

interface CardLike<C extends string> {
    key: string;
    column: C;
    position: number;
}

/**
 * Optimistic move: take `key` out of its column and put it in `column` at
 * `position` (1-based; end of the column when omitted). Both columns are
 * renumbered from 1.
 */
export function moveTask<C extends string, T extends CardLike<C>>(tasks: readonly T[], key: string, column: NoInfer<C>, position?: number): T[] {
    const card = tasks.find((task) => task.key === key);
    if (!card) return [...tasks];
    const target = tasks.filter((task) => task.column === column && task.key !== key).sort((a, b) => a.position - b.position);
    const index = position === undefined ? target.length : Math.min(Math.max(position - 1, 0), target.length);
    target.splice(index, 0, { ...card, column });
    const source = tasks.filter((task) => task.column === card.column && task.key !== key && card.column !== column).sort((a, b) => a.position - b.position);
    const renumbered = new Map<string, T>();
    target.forEach((task, i) => renumbered.set(task.key, { ...task, position: i + 1 }));
    source.forEach((task, i) => renumbered.set(task.key, { ...task, position: i + 1 }));
    return tasks.map((task) => renumbered.get(task.key) ?? task);
}

/** "Quedarían 3 de 3" while dragging into a column with a WIP limit; null otherwise. */
export function dropHint<C extends string>(tasks: readonly CardLike<C>[], column: NoInfer<C>, draggingKey: string, limit: number | undefined): string | null {
    if (limit === undefined) return null;
    const card = tasks.find((task) => task.key === draggingKey);
    if (!card || card.column === column) return null;
    const count = tasks.filter((task) => task.column === column).length + 1;
    return `Quedarían ${count} de ${limit}`;
}
