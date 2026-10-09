import { router, useForm } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { mergeOutcome } from '@/Components/ai/triage/triage';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Dialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import type { RequestSummary } from '@/types/ai';
import type { Company } from '@/types/shared';
import { route } from '@/lib/route';

export interface MergeDialogProps {
    open: boolean;
    onClose: () => void;
    /** The request that closes as a duplicate. */
    request: RequestSummary;
    /** Results of the search (`merge_candidates` partial reload). */
    candidates: RequestSummary[];
    /** Key to preselect (the duplicate banner's suggestion). */
    preselect?: string | null;
    companies: Company[];
}

const SEARCH_DELAY_MS = 400;
const MIN_QUERY = 3;

function candidateLine(candidate: RequestSummary): string {
    const parts = [candidate.area.name];
    if (candidate.votes > 0) parts.push(`${candidate.votes} ${candidate.votes === 1 ? 'persona la pidió' : 'personas la pidieron'}`);
    if (candidate.project_key) parts.push(`proyecto ${candidate.project_key}`);
    else if (candidate.rank !== null) parts.push(`puesto ${candidate.rank} del backlog`);
    return parts.join(' · ');
}

/**
 * "Unir con otra": search the request that stays, pick it and see what
 * happens (votes, followers, answers move over) before confirming.
 */
export function MergeDialog({ open, onClose, request, candidates, preselect, companies }: MergeDialogProps) {
    const form = useForm<{ into: string }>({ into: '' });
    const [query, setQuery] = useState('');
    const timer = useRef<number | undefined>(undefined);
    const searchId = useId();
    const options = candidates.filter((candidate) => candidate.key !== request.key);
    const chosen = options.find((candidate) => candidate.key === form.data.into);

    useEffect(() => {
        if (open) {
            form.clearErrors();
            form.setData('into', preselect ?? options[0]?.key ?? '');
            setQuery('');
            // Ask the server for candidates right away, starting from the suggested duplicate.
            router.reload({ only: ['merge_candidates'], data: { q: preselect ?? request.title } });
        }
    }, [open, request.key]);

    // Results arrive after opening: preselect the first one if nothing is chosen yet.
    useEffect(() => {
        if (open && !chosen && options[0]) form.setData('into', options[0].key);
    }, [open, candidates]);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const search = (value: string) => {
        setQuery(value);
        window.clearTimeout(timer.current);
        const q = value.trim();
        if (q.length < MIN_QUERY) return;
        timer.current = window.setTimeout(() => {
            router.reload({ only: ['merge_candidates'], data: { q } });
        }, SEARCH_DELAY_MS);
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.into) {
            form.setError('into', 'Elige la solicitud que se queda.');
            return;
        }
        form.post(route('ai.requests.merge', { request: request.key }), { preserveScroll: true, onSuccess: onClose });
    };

    const outcome = chosen ? mergeOutcome({ key: request.key, votes: request.votes, requester: request.requester.name }, chosen) : null;

    return (
        <Dialog
            open={open}
            onClose={onClose}
            onSubmit={submit}
            size="lg"
            title={`Unir ${request.key} con otra`}
            description={request.title}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" icon="pull-request" loading={form.processing} disabled={!chosen}>
                        {chosen ? `Unir con ${chosen.key}` : 'Unir'}
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-1.5">
                <label htmlFor={searchId} className="text-sm font-semibold">
                    Buscar la solicitud que se queda
                </label>
                <span className="flex h-11 items-center gap-2.5 rounded-control border border-line-strong bg-surface px-3.5 text-fg-muted has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg">
                    <Icon name="search" size={16} />
                    <input
                        id={searchId}
                        type="search"
                        value={query}
                        placeholder="Clave o palabras del título"
                        onChange={(event) => search(event.target.value)}
                        className="min-w-0 flex-1 bg-transparent text-base text-fg outline-none placeholder:text-fg-muted md:text-sm"
                    />
                </span>
            </div>

            <fieldset className="flex flex-col gap-2">
                <legend className="sr-only">Resultados</legend>
                {options.length === 0 ? (
                    <p className="rounded-control border border-dashed border-line-strong p-4 text-sm text-fg-muted">
                        No hay solicitudes parecidas. Escribe la clave (GIA-121) o palabras del título.
                    </p>
                ) : null}
                {options.map((candidate) => {
                    const company = companies.find((item) => item.id === candidate.company_id);
                    const selected = candidate.key === form.data.into;
                    return (
                        <label
                            key={candidate.key}
                            className={cx(
                                'flex cursor-pointer items-start gap-3 rounded-control border p-3 transition-colors duration-150',
                                selected ? 'border-fg bg-ground' : 'border-line hover:bg-ground',
                            )}
                        >
                            <input
                                type="radio"
                                name="merge-into"
                                value={candidate.key}
                                checked={selected}
                                onChange={() => {
                                    form.setData('into', candidate.key);
                                    form.clearErrors('into');
                                }}
                                className="mt-1 size-5 shrink-0 accent-brand-button"
                            />
                            <span className="flex min-w-0 flex-col gap-1 text-sm">
                                <span className="flex flex-wrap items-center gap-2">
                                    {company ? <CompanyMark company={company} size={20} /> : null}
                                    <b className="tabular">{candidate.key}</b>
                                    <StatusChip status={candidate.status} size="sm" />
                                </span>
                                <span className="font-semibold leading-snug">{candidate.title}</span>
                                <span className="text-[13px] text-fg-muted">{candidateLine(candidate)}</span>
                            </span>
                        </label>
                    );
                })}
                {form.errors.into ? (
                    <p className="flex items-center gap-1.5 text-[13px] font-semibold text-status-failure">
                        <Icon name="alert-triangle" size={14} />
                        {form.errors.into}
                    </p>
                ) : null}
            </fieldset>

            {outcome ? (
                <div className="flex flex-col gap-2 rounded-control bg-ground p-3.5 text-[13px] leading-snug text-fg-soft">
                    <b className="text-sm text-fg">Qué pasa al unir</b>
                    <ul className="flex flex-col gap-1.5">
                        {outcome.lines.map((line) => (
                            <li key={line} className="flex items-start gap-2">
                                <Icon name="check" size={14} className="mt-0.5" />
                                {line}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </Dialog>
    );
}
