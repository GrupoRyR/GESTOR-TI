import { Link } from '@inertiajs/react';
import { useId } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { formatCOP, formatDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { ApprovalItem } from '@/types/pages/home';

/** "40 h · $ 6.400.000 · entrega 31 oct" */
export function approvalSummary(item: ApprovalItem): string {
    const due = formatDate(item.due_date).replace(/ \d{4}$/, '');
    return `${item.estimate_hours} h · ${formatCOP(item.cost_cop)} · entrega ${due}`;
}

/** Projects waiting for the approver's decision (role Aprobador de proyectos only). */
export function ApprovalsPanel({ approvals }: { approvals: ApprovalItem[] }) {
    const titleId = useId();
    return (
        <section aria-labelledby={titleId} className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5 lg:p-[22px]">
            <h2 id={titleId} className="text-lg font-bold">
                Esperan tu aprobación
            </h2>
            {approvals.length === 0 ? (
                <p className="text-sm text-fg-muted">No hay proyectos esperando tu decisión.</p>
            ) : (
                <ul className="flex flex-col gap-2.5">
                    {approvals.map((item) => (
                        <li key={item.key}>
                            <Link
                                href={route('ai.approvals.show', { request: item.key })}
                                className="flex min-h-11 items-center justify-between gap-3 rounded-control bg-ground p-3.5 text-sm no-underline transition-colors duration-150 hover:bg-subtle lg:p-3"
                            >
                                <span className="flex min-w-0 flex-col gap-0.5">
                                    <b className="font-bold">
                                        {item.key} · {item.title}
                                    </b>
                                    <span className="tabular text-[13px] text-fg-soft">{approvalSummary(item)}</span>
                                </span>
                                <Icon name="chevron-right" size={18} className="text-fg-muted lg:hidden" />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
