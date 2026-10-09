import { Link } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { PhaseRail } from '@/Components/ui/PhaseRail';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import { route } from '@/lib/route';
import { requestStatus } from '@/lib/status';
import type { RequesterRequest } from '@/types/pages/home';

/**
 * One of "Mis solicitudes". The whole card opens the request (stretched
 * link on the title); "Calificar" sits above it as its own link. Amber
 * statuses (the requester or an approver must act) get an amber border.
 */
export function RequestCard({ request }: { request: RequesterRequest }) {
    const { family } = requestStatus(request.status);
    const href = route('ai.requests.show', { request: request.key });

    return (
        <article
            className={cx(
                'relative flex flex-col gap-2.5 rounded-[14px] border bg-surface p-3.5 transition-colors duration-150 hover:bg-ground has-[a:focus-visible]:bg-ground md:p-4',
                family === 'amber' ? 'border-status-maintenance-dot/50' : 'border-line',
            )}
        >
            <div className="flex items-center justify-between gap-3 text-[13px]">
                <b className="tabular font-bold">{request.key}</b>
                <StatusChip status={request.status} size="sm" />
            </div>
            <h3 className="font-sans text-[15px] leading-[1.3] font-semibold tracking-normal">
                <Link
                    href={href}
                    className="text-inherit no-underline outline-none after:absolute after:inset-0 after:rounded-[14px] after:content-[''] focus-visible:after:outline-2 focus-visible:after:outline-offset-2 focus-visible:after:outline-fg"
                >
                    {request.title}
                </Link>
            </h3>
            <PhaseRail status={request.status} variant="bar" />
            {request.can_rate ? (
                <div className="flex items-center justify-between gap-3">
                    <span className="text-[13px] text-fg-soft">¿Cómo te fue con la entrega?</span>
                    <span className="relative z-10">
                        <Button href={route('ai.ratings.create', { request: request.key })} aria-label={`Calificar ${request.key}`}>
                            Calificar
                        </Button>
                    </span>
                </div>
            ) : request.roadmap_position ? (
                <p className="text-[13px] text-fg-soft">{request.roadmap_position}</p>
            ) : request.next_step ? (
                <p className="text-[13px] text-fg-soft">{request.next_step}</p>
            ) : null}
        </article>
    );
}
