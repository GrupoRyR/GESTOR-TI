import { Link, router } from '@inertiajs/react';
import { useId, useState, type FormEvent, type ReactNode } from 'react';
import { SparkleTile } from '@/Components/suggestions/SuggestionBlock';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Icon } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { TextArea } from '@/Components/ui/TextArea';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { formatRelative, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { CompareSide, RequestsCompareProps } from '@/types/pages/ai-triage';
import type { Company, PageProps } from '@/types/shared';

type Props = PageProps<RequestsCompareProps>;

interface Row {
    label: string;
    render: (side: CompareSide) => ReactNode;
}

const ROWS: Row[] = [
    { label: 'Problema', render: (side) => side.problem },
    { label: 'Resultado esperado', render: (side) => side.expected_result },
    { label: 'App', render: (side) => (side.systems.length > 0 ? side.systems.join(' · ') : <span className="text-fg-muted">Sin app</span>) },
    {
        label: 'Quién la pidió',
        render: ({ request }) => (
            <>
                {request.requester.name}
                {request.requester.job_title ? `, ${request.requester.job_title}` : ''} · {request.area.name}
            </>
        ),
    },
    {
        label: 'Votos',
        render: ({ request }) => (
            <>
                <b>
                    +{request.votes} {request.votes === 1 ? 'voto' : 'votos'}
                </b>
                {request.people !== null ? ` · ${request.people} ${request.people === 1 ? 'persona' : 'personas'}` : ''}
                {request.rank !== null ? ` · puesto ${request.rank} del backlog` : ''}
            </>
        ),
    },
    {
        label: 'Estado',
        render: ({ request }) => (
            <span className="flex flex-wrap items-center gap-1.5">
                <StatusChip status={request.status} size="sm" />
                <span className="text-fg-muted">· entró el {formatShortDate(request.created_at)}</span>
            </span>
        ),
    },
];

function SideHeading({ side, note, company, link }: { side: CompareSide; note: string; company: Company | undefined; link: boolean }) {
    const { request } = side;
    return (
        <span className="flex flex-col gap-1 text-left">
            <span className="flex flex-wrap items-center gap-1.5 text-[13px] font-semibold text-fg-soft">
                {company ? <CompanyMark company={company} size={20} /> : null}
                {link ? (
                    <Link href={route('ai.requests.show', { request: request.key })} className="tabular font-bold text-fg underline underline-offset-2">
                        {request.key}
                    </Link>
                ) : (
                    <b className="tabular text-fg">{request.key}</b>
                )}
                <span>· {note}</span>
            </span>
            <span className="text-sm leading-snug font-bold text-fg">{request.title}</span>
        </span>
    );
}

/**
 * "¿GIA-148 es la misma solicitud que GIA-121?" (D-SuggestDuplicate): the
 * AI duplicate suggestion side by side. Jhon decides: "Unir" opens the
 * usual merge dialog on the request page; "No es duplicado" only hides the
 * suggestion on this request.
 */
export default function RequestsCompare(props: Props) {
    const { current, other, suggestion, companies, now } = props;
    const [notDuplicateOpen, setNotDuplicateOpen] = useState(props.not_duplicate_open ?? false);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const formId = useId();
    const payload = suggestion.payload;
    const companyOf = (side: CompareSide) => companies.find((company) => company.id === side.request.company_id);
    const sides = [
        { side: current, note: 'esta solicitud', link: false },
        { side: other, note: 'posible duplicado', link: true },
    ];

    const hide = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.post(
            route('ai.suggestions.discard', { suggestion: suggestion.id }),
            { reason: reason.trim() || null },
            { onStart: () => setProcessing(true), onFinish: () => setProcessing(false) },
        );
    };

    return (
        <GestorLayout
            shared={props}
            title={`${current.request.key} vs ${other.request.key} · Bandeja`}
            tab="inbox"
            mode="it"
            breadcrumb={[
                { label: 'Bandeja', href: route('ai.inbox.index') },
                { label: current.request.key, href: route('ai.requests.show', { request: current.request.key }) },
                { label: `Comparar con ${other.request.key}` },
            ]}
        >
            <div className="mx-auto flex w-full max-w-[1280px] flex-col gap-5">
                <header className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div className="flex min-w-0 flex-col gap-2">
                        <span className="flex flex-wrap items-center gap-2 text-[13px] text-fg-soft">
                            <SparkleTile size={24} />
                            <b className="text-fg">Sugerencia de IA</b>
                            <span>· revísala antes de usarla ·</span>
                            <span className="text-fg-muted">
                                {suggestion.provider} · {formatRelative(suggestion.created_at, new Date(now))}
                            </span>
                        </span>
                        <h1 className="max-w-[40ch] font-display text-[24px] leading-[1.15] font-bold tracking-[-0.02em] md:text-[30px]">
                            ¿{current.request.key} es la misma solicitud que {other.request.key}?
                        </h1>
                    </div>
                    <Button variant="secondary" icon="chevron-left" href={route('ai.requests.show', { request: current.request.key })} className="self-start md:self-auto">
                        Volver a {current.request.key}
                    </Button>
                </header>

                <div className="flex flex-col gap-5 lg:grid lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start lg:gap-6">
                    <div className="flex min-w-0 flex-col gap-5">
                        <section aria-label="Comparación lado a lado" className="overflow-hidden rounded-card border border-line bg-surface">
                            <table className="hidden w-full table-fixed border-collapse text-sm md:table">
                                <thead>
                                    <tr className="border-b border-line bg-ground">
                                        <th scope="col" className="w-[150px] px-4 py-3 text-left text-xs font-semibold text-fg-muted">
                                            Campo
                                        </th>
                                        {sides.map(({ side, note, link }) => (
                                            <th key={side.request.key} scope="col" className="px-4 py-3 align-top font-normal">
                                                <SideHeading side={side} note={note} company={companyOf(side)} link={link} />
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {ROWS.map((row) => (
                                        <tr key={row.label} className="border-b border-line last:border-b-0">
                                            <th scope="row" className="px-4 py-3 text-left align-top text-[13px] font-semibold text-fg-muted">
                                                {row.label}
                                            </th>
                                            {sides.map(({ side }) => (
                                                <td key={side.request.key} className="px-4 py-3 align-top leading-normal">
                                                    {row.render(side)}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            <div className="flex flex-col divide-y divide-line md:hidden">
                                {sides.map(({ side, note, link }) => (
                                    <div key={side.request.key} className="flex flex-col gap-3 p-4">
                                        <SideHeading side={side} note={note} company={companyOf(side)} link={link} />
                                        <dl className="flex flex-col gap-2.5">
                                            {ROWS.map((row) => (
                                                <div key={row.label} className="flex flex-col gap-0.5">
                                                    <dt className="text-xs font-semibold text-fg-muted">{row.label}</dt>
                                                    <dd className="text-sm leading-normal">{row.render(side)}</dd>
                                                </div>
                                            ))}
                                        </dl>
                                    </div>
                                ))}
                            </div>
                        </section>

                        {payload ? (
                            <section aria-label="Por qué se parecen y en qué se diferencian" className="grid gap-4 rounded-card border border-line bg-surface p-4 md:grid-cols-2 md:p-5">
                                <div className="flex flex-col gap-2">
                                    <h2 className="flex flex-wrap items-center gap-2 text-base font-bold">
                                        Por qué se parecen
                                        <span className="inline-flex h-6 items-center rounded-chip bg-subtle px-2 text-xs font-bold text-fg-soft">Parecido {payload.level}</span>
                                    </h2>
                                    <ul className="flex list-disc flex-col gap-1.5 pl-5 text-sm leading-normal">
                                        {payload.reasons.map((reason) => (
                                            <li key={reason}>{reason}</li>
                                        ))}
                                    </ul>
                                </div>
                                <div className="flex flex-col gap-2">
                                    <h2 className="text-base font-bold">En qué se diferencian</h2>
                                    {payload.differences.length > 0 ? (
                                        <>
                                            <ul className="flex list-disc flex-col gap-1.5 pl-5 text-sm leading-normal">
                                                {payload.differences.map((difference) => (
                                                    <li key={difference}>{difference}</li>
                                                ))}
                                            </ul>
                                            <p className="text-xs text-fg-muted">Si las unes, puedes copiar estos detalles a {other.request.key}.</p>
                                        </>
                                    ) : (
                                        <p className="text-sm text-fg-muted">La IA no encontró diferencias.</p>
                                    )}
                                </div>
                            </section>
                        ) : null}
                    </div>

                    <aside aria-label="Decisión" className="flex flex-col gap-3 lg:sticky lg:top-4">
                        <section className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
                            <h2 className="font-display text-xl font-bold">Tú decides</h2>
                            <p className="text-sm leading-normal text-fg-soft">
                                Al unir se abre el mismo diálogo de siempre: {current.request.key} se cierra como duplicada, sus votos pasan a {other.request.key} y{' '}
                                {current.request.requester.name.split(' ')[0]} queda siguiendo {other.request.key}.
                            </p>
                            <Button
                                variant="dark"
                                icon="merge"
                                fullWidth
                                href={route('ai.requests.show', { request: current.request.key, unir: other.request.key })}
                            >
                                Unir con {other.request.key}
                            </Button>
                            <Button
                                variant="secondary"
                                fullWidth
                                aria-expanded={notDuplicateOpen}
                                aria-controls={formId}
                                onClick={() => setNotDuplicateOpen((value) => !value)}
                            >
                                No es duplicado
                            </Button>
                        </section>

                        {notDuplicateOpen ? (
                            <form id={formId} onSubmit={hide} aria-label="No es duplicado" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
                                <TextArea
                                    label="¿Por qué no es duplicado?"
                                    optional
                                    rows={3}
                                    value={reason}
                                    onChange={(event) => setReason(event.target.value)}
                                    placeholder={`Ej.: ${other.request.key} es solo para la app; ${current.request.requester.name.split(' ')[0]} necesita el correo`}
                                />
                                <p className="flex items-start gap-1.5 text-xs leading-normal text-fg-muted">
                                    <Icon name="info" size={13} className="mt-0.5 shrink-0" />
                                    Solo oculta esta sugerencia en {current.request.key} y queda en el historial. No cambia {other.request.key} ni entrena a la IA.
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button type="submit" variant="dark" loading={processing}>
                                        Ocultar sugerencia
                                    </Button>
                                    <Button variant="secondary" onClick={() => setNotDuplicateOpen(false)}>
                                        Cancelar
                                    </Button>
                                </div>
                            </form>
                        ) : null}
                    </aside>
                </div>
            </div>
        </GestorLayout>
    );
}
