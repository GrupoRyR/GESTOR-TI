import type { ReactNode } from 'react';
import { Alert } from '@/Components/ui/Alert';
import { Avatar } from '@/Components/ui/Avatar';
import { BudgetBar } from '@/Components/ui/BudgetBar';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { daysLate } from '@/lib/budget';
import type { PersonRef, ProjectBudget } from '@/types/ai';
import { budgetLines } from './projects';

/** "Visible solo con permiso de costos (ai.costs.view)". */
export function CostsLock({ className }: { className?: string }) {
    return (
        <span className={cx('inline-flex items-center gap-1.5 text-xs font-semibold text-fg-muted', className)}>
            <Icon name="lock" size={13} />
            Visible solo con permiso de costos (ai.costs.view)
        </span>
    );
}

export interface BudgetBarsProps {
    budget: Pick<ProjectBudget, 'hours' | 'cost' | 'due_date' | 'projected_due_date' | 'approved_at'>;
    /** Hide the cost bar (no `ai.costs.view`, or the requester version of the report). */
    showCost: boolean;
    className?: string;
}

/**
 * Hours, cost and date bars with their 80 / 100 marks. The date bar compares
 * the projected duration (approval → projected delivery) to the approved one.
 */
export function BudgetBars({ budget, showCost, className }: BudgetBarsProps) {
    const lines = budgetLines(budget);
    const approvedDays = Math.max(1, daysLate(budget.due_date, budget.approved_at));
    const projectedDays = daysLate(budget.projected_due_date, budget.approved_at);
    return (
        <div className={cx('flex flex-col gap-4', className)}>
            <BudgetBar label="Horas" value={budget.hours.actual} max={budget.hours.estimate} valueText={lines.hours.value} message={lines.hours.message} />
            {showCost && budget.cost && lines.cost ? (
                <BudgetBar label="Costo" value={budget.cost.actual} max={budget.cost.estimate} valueText={lines.cost.value} message={lines.cost.message} />
            ) : null}
            <BudgetBar label="Fecha" value={projectedDays} max={approvedDays} valueText={lines.date.value} message={lines.date.message} />
        </div>
    );
}

export interface ReapprovalBannerProps {
    sentence: string;
    /** "La entrega proyectada es el 14 nov y se aprobó para el 31 oct." */
    detail: string;
    approvers: PersonRef[];
    requestedAt: string | null;
    /** Formatted "hoy 9:40 a. m." of `requestedAt`. */
    requestedLabel: string | null;
    onRequest: () => void;
    processing?: boolean;
}

const joinNames = (people: PersonRef[]) =>
    people.length <= 1 ? (people[0]?.name ?? 'los aprobadores') : `${people.slice(0, -1).map((p) => p.name).join(', ')} o ${people[people.length - 1]!.name}`;

/** Amber banner when actual passed the re-approval threshold, with "Pedir nueva aprobación". */
export function ReapprovalBanner({ sentence, detail, approvers, requestedAt, requestedLabel, onRequest, processing }: ReapprovalBannerProps) {
    return (
        <Alert
            tone="warning"
            title={sentence}
            actions={
                requestedAt ? (
                    <span className="inline-flex h-11 items-center gap-1.5 text-[13px] font-bold">
                        <Icon name="hourglass" size={14} />
                        Pedida {requestedLabel}
                    </span>
                ) : (
                    <button
                        type="button"
                        onClick={onRequest}
                        disabled={processing}
                        className="inline-flex h-11 items-center rounded-control bg-fg px-4 text-sm font-bold whitespace-nowrap text-on-fg hover:brightness-[1.15] disabled:opacity-60 dark:hover:brightness-[0.9]"
                    >
                        Pedir nueva aprobación
                    </button>
                )
            }
        >
            {detail} Hasta que {joinNames(approvers)} {approvers.length > 1 ? 'aprueben' : 'apruebe'}, el plan aprobado sigue siendo el anterior.
        </Alert>
    );
}

/** Avatar + name + secondary line, used in team lists and recipients. */
export function PersonLine({ person, detail, trailing, size = 'sm' }: { person: PersonRef; detail?: ReactNode; trailing?: ReactNode; size?: 'xs' | 'sm' }) {
    return (
        <div className="flex min-h-11 min-w-0 items-center gap-2.5">
            <Avatar name={person.name} src={person.avatar_url} size={size} />
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-sm font-semibold">{person.name}</span>
                {detail ? <span className="truncate text-[13px] text-fg-muted">{detail}</span> : null}
            </span>
            {trailing}
        </div>
    );
}

/** Section title used inside panels and drawers. */
export function SectionTitle({ children, aside, id }: { children: ReactNode; aside?: ReactNode; id?: string }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
            <h2 id={id} className="text-base font-bold">
                {children}
            </h2>
            {aside}
        </div>
    );
}
