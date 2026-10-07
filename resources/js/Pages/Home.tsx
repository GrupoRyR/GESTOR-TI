import { Link } from '@inertiajs/react';
import { ApprovalsPanel } from '@/Components/home/ApprovalsPanel';
import { AttentionCard } from '@/Components/home/AttentionCard';
import { AttentionList, type AttentionRow } from '@/Components/home/AttentionList';
import { ATTENTION_GROUPS, attentionTotal, countTone, groupHref } from '@/Components/home/attention';
import { greeting, longDate } from '@/Components/home/greeting';
import { MonitoringBlock, MonitoringRow } from '@/Components/home/MonitoringBlock';
import { RequestCard } from '@/Components/home/RequestCard';
import { Button } from '@/Components/ui/Button';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { AppLayout } from '@/Layouts/AppLayout';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import type { HomeItProps, HomeProps, HomeRequesterProps } from '@/types/pages/home';
import type { PageProps, SharedProps } from '@/types/shared';

function attentionSentence(total: number): string {
    if (total === 0) return 'Nada requiere atención';
    return total === 1 ? '1 cosa requiere atención' : `${total} cosas requieren atención`;
}

// ---- IT view --------------------------------------------------------------

function ItHome({ shared, view }: { shared: SharedProps; view: HomeItProps }) {
    const { attention, pending_approvals, monitoring, greeting_name } = view;
    const canApprove = can(shared.auth.user, 'ai.projects.approve');
    const total = attentionTotal(attention);
    const now = new Date();

    // Phone list: the four groups with approvals in second place, as in the canvas.
    const rows: AttentionRow[] = ATTENTION_GROUPS.map((group) => {
        const items = attention[group.key];
        const [first] = items;
        return {
            key: group.key,
            title: group.title,
            count: items.length,
            summary: first ? first.title : group.empty,
            href: groupHref(group.key, items),
            tone: countTone(items.map((item) => item.severity)),
        };
    });
    if (canApprove) {
        const [first] = pending_approvals;
        rows.splice(1, 0, {
            key: 'approvals',
            title: 'Esperan tu aprobación',
            count: pending_approvals.length,
            summary:
                pending_approvals.length === 0
                    ? 'Nada por aprobar.'
                    : pending_approvals.length <= 2
                      ? pending_approvals.map((item) => item.key).join(' y ')
                      : `${pending_approvals.length} proyectos`,
            href:
                pending_approvals.length === 1 && first
                    ? route('ai.approvals.show', { request: first.key })
                    : route('ai.requests.index', { status: 'pending_approval' }),
            tone: pending_approvals.length > 0 ? 'text-status-maintenance' : 'text-fg-muted',
        });
    }

    return (
        <div className="flex flex-col gap-4 md:gap-[18px] lg:gap-5">
            <div className="flex flex-col gap-0.5 md:gap-1 lg:flex-row lg:items-baseline lg:justify-between lg:gap-4">
                <h1 className="text-[28px] leading-tight font-bold md:text-[32px] lg:text-[34px]">
                    {greeting(now)}, {greeting_name}
                </h1>
                <p className="text-sm text-fg-muted">
                    <span className="hidden lg:inline">{longDate(now)} · </span>
                    {attentionSentence(total)}
                </p>
            </div>

            {/* Phone */}
            <div className="flex flex-col gap-4 md:hidden">
                <AttentionList rows={rows} />
                <MonitoringRow monitoring={monitoring} />
            </div>

            {/* Tablet: 2 × 2, desktop: 4 across */}
            <div className="hidden grid-cols-2 gap-3.5 md:grid lg:grid-cols-4 lg:gap-4">
                {ATTENTION_GROUPS.map((group) => (
                    <AttentionCard key={group.key} title={group.title} items={attention[group.key]} empty={group.empty} />
                ))}
            </div>

            <div
                className={cx(
                    'hidden gap-[18px] md:grid lg:gap-4',
                    canApprove && 'lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]',
                )}
            >
                <MonitoringBlock monitoring={monitoring} className="md:order-2 lg:order-none" />
                {canApprove ? <ApprovalsPanel approvals={pending_approvals} /> : null}
            </div>
        </div>
    );
}

// ---- requester view -------------------------------------------------------

function RequesterHome({ view }: { view: HomeRequesterProps }) {
    const { requests, greeting_name } = view;
    const createHref = route('ai.requests.create');

    return (
        <div className="mx-auto flex w-full max-w-[720px] flex-col gap-[18px] md:gap-6">
            <h1 className="text-[28px] leading-tight font-bold md:text-[32px]">Hola, {greeting_name}</h1>

            <Link
                href={createHref}
                className="flex min-h-[76px] items-center gap-3.5 rounded-card bg-brand-button p-[18px] text-brand-button-ink no-underline transition-[filter] duration-150 hover:brightness-[0.93]"
            >
                <span className="flex size-11 shrink-0 items-center justify-center rounded-control bg-white/15 dark:bg-black/10">
                    <Icon name="plus" size={22} strokeWidth={2.4} />
                </span>
                <span className="flex flex-col gap-0.5">
                    <b className="text-[17px] leading-tight font-bold">Pedir un desarrollo o una mejora</b>
                    <span className="text-[13px] opacity-85">Toma unos 4 minutos</span>
                </span>
            </Link>

            {requests.length === 0 ? (
                <EmptyState
                    icon="inbox"
                    title="Todavía no has pedido nada"
                    description="Cuando algo de tu trabajo se pueda hacer más rápido con una app o un reporte, pídelo aquí. Verás en qué fase va sin tener que preguntar."
                    actions={
                        <Button href={createHref} icon="plus">
                            Hacer mi primera solicitud
                        </Button>
                    }
                />
            ) : (
                <section aria-labelledby="mis-solicitudes" className="flex flex-col gap-2.5">
                    <div className="flex items-baseline justify-between gap-3">
                        <h2 id="mis-solicitudes" className="text-lg font-bold">
                            Mis solicitudes
                        </h2>
                        <Link
                            href={route('ai.requests.index')}
                            className="inline-flex min-h-11 items-center text-[13px] font-semibold underline underline-offset-[3px]"
                        >
                            Ver todas
                        </Link>
                    </div>
                    <ul className="flex flex-col gap-2.5">
                        {requests.map((request) => (
                            <li key={request.key}>
                                <RequestCard request={request} />
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}

// ---- page -----------------------------------------------------------------

/** Inicio: the IT team's attention board, or a requester's own requests. */
export default function Home(props: PageProps<HomeProps>) {
    return (
        <AppLayout shared={props} title="Inicio · Gestor TI" active="home">
            {props.view === 'it' ? <ItHome shared={props} view={props} /> : <RequesterHome view={props} />}
        </AppLayout>
    );
}
