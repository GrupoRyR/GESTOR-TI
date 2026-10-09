import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { RequestActionMenu } from '@/Components/ai/requests/actions/RequestActionMenu';
import type { RequestAction } from '@/Components/ai/requests/actions/RequestActionDialogs';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Icon } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import { route } from '@/lib/route';
import type { RequestSummary } from '@/types/ai';
import type { Company } from '@/types/shared';
import { DueText } from './FirstResponse';
import { firstResponseState, sinceLabel } from './triage';

const number = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

/** "Paola Ríos · 6 h/sem × 8 personas · +3 votos"; needs_info rows say how long the requester has had the question. */
export function requestMeta(request: RequestSummary, now: Date, withArea = false): string {
    const parts = [request.requester.name];
    if (withArea) parts.push(request.area.name);
    if (request.status === 'needs_info' && request.first_response.answered_at) {
        parts.push(`espera su respuesta ${sinceLabel(request.first_response.answered_at, now)}`);
    } else if (!withArea) {
        parts.push(
            request.hours_per_week !== null && request.people !== null
                ? `${number(request.hours_per_week)} h/sem × ${request.people} ${request.people === 1 ? 'persona' : 'personas'}`
                : 'tiempo de hoy sin dato',
        );
    }
    if (request.votes > 0) parts.push(`+${request.votes} ${request.votes === 1 ? 'voto' : 'votos'}`);
    return parts.join(' · ');
}

export interface SelectBoxProps {
    label: string;
    checked: boolean;
    indeterminate?: boolean;
    onChange: (checked: boolean) => void;
}

/** 20 px checkbox inside a 44 px target, for row selection. */
export function SelectBox({ label, checked, indeterminate = false, onChange }: SelectBoxProps) {
    return (
        <label className="-m-3 flex size-11 cursor-pointer items-center justify-center">
            <input
                type="checkbox"
                aria-label={label}
                checked={checked}
                ref={(element) => {
                    if (element) element.indeterminate = indeterminate;
                }}
                onChange={(event) => onChange(event.target.checked)}
                className="size-5 cursor-pointer accent-brand-button"
            />
        </label>
    );
}

export interface InboxCardProps {
    request: RequestSummary;
    company: Company | undefined;
    /** Show the company mark ("Todas"). */
    showCompany: boolean;
    now: Date;
    selected: boolean;
    onSelect: (checked: boolean) => void;
    onAction: (action: RequestAction) => void;
}

/** Phone and tablet row of the inbox: what it is, the first-response state and "Evaluar". */
export function InboxCard({ request, company, showCompany, now, selected, onSelect, onAction }: InboxCardProps) {
    const state = firstResponseState(request.first_response, request.created_at, now);
    const href = route('ai.requests.show', { request: request.key });
    return (
        <article
            className={cx(
                'flex flex-col gap-2.5 rounded-card border bg-surface p-4',
                selected ? 'border-fg shadow-[inset_3px_0_0_var(--color-fg)]' : 'border-line',
            )}
        >
            <div className="flex items-center gap-2">
                {showCompany && company ? <CompanyMark company={company} size={22} /> : null}
                <Link href={href} className="tabular text-sm font-bold no-underline hover:underline">
                    {request.key}
                </Link>
                <StatusChip status={request.status} size="sm" />
                <span className="ml-auto hidden md:flex">
                    <SelectBox label={`Seleccionar ${request.key}`} checked={selected} onChange={onSelect} />
                </span>
            </div>
            <Link href={href} className="text-[15px] leading-snug font-bold text-fg no-underline hover:underline">
                {request.title}
            </Link>
            <p className="text-[13px] text-fg-muted">{requestMeta(request, now, true)}</p>
            {request.similar_to ? (
                <p className="flex items-center gap-1.5 text-[13px] text-fg-soft">
                    <Icon name="pull-request" size={14} />
                    Se parece a {request.similar_to.key} · {request.similar_to.votes} personas
                </p>
            ) : null}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <DueText level={state.level} label={state.label} />
                <span className="text-[13px] text-fg-muted">
                    {request.priority_final !== null ? `Prioridad ${request.priority_final}` : 'Prioridad: sin evaluar'}
                </span>
            </div>
            <div className="flex items-center gap-2 border-t border-line pt-3">
                <Button variant="secondary" href={href} className="flex-1">
                    Evaluar
                </Button>
                <RequestActionMenu request={request} onAction={onAction} className="border border-line-strong" />
            </div>
        </article>
    );
}

export interface FilterSelectProps {
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}

/** Compact filter "Área: todas ▾" as a native select. */
export function FilterSelect({ label, value, options, onChange }: FilterSelectProps): ReactNode {
    return (
        <label className="relative inline-flex h-11 shrink-0 items-center gap-1 rounded-control border border-line-strong bg-surface pr-9 pl-3 text-[13px] has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg">
            <span className="text-fg-soft">{label}:</span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="absolute inset-0 cursor-pointer appearance-none opacity-0"
                aria-label={label}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <b aria-hidden="true" className="max-w-[140px] truncate">
                {options.find((option) => option.value === value)?.label ?? ''}
            </b>
            <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-fg-muted">
                <Icon name="chevron-down" size={14} strokeWidth={2.2} />
            </span>
        </label>
    );
}
