import { Link } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { PhaseRail } from '@/Components/ui/PhaseRail';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { MyRequestRow } from '@/types/pages/ai-requester';

export interface MyRequestCardProps {
    request: MyRequestRow;
    /** "Now" for relative dates (mocks pass the fixed mock instant). */
    now?: Date;
}

/** One request in "Mis solicitudes": phase, next step and the action waiting for the requester. */
export function MyRequestCard({ request, now }: MyRequestCardProps) {
    const href = route('ai.requests.show', { request: request.key });
    const asking = request.needs_reply;

    return (
        <article
            aria-labelledby={`req-${request.key}`}
            className={cx(
                'flex flex-col overflow-hidden rounded-card border bg-surface',
                asking ? 'border-2 border-status-maintenance-dot' : 'border-line',
            )}
        >
            {asking ? (
                <p className="flex items-center gap-2 bg-status-maintenance-bg px-4 py-2 text-[13px] font-bold text-status-maintenance">
                    <Icon name="question" size={16} />
                    TI te hizo una pregunta
                </p>
            ) : null}
            <Link href={href} className="flex flex-col gap-2 p-4 no-underline hover:bg-subtle/60 focus-visible:-outline-offset-2">
                <span className="flex flex-wrap items-center gap-2">
                    <b className="tabular text-[13px] text-fg-soft">{request.key}</b>
                    <StatusChip status={request.status} size="sm" />
                </span>
                <b id={`req-${request.key}`} className="text-[16px] leading-snug">
                    {request.title}
                </b>
                <PhaseRail status={request.status} variant="bar" className="mt-1" />
                <span className="text-[13px] leading-snug text-fg-muted">{request.next_step}</span>
            </Link>
            {asking && request.question ? (
                <div className="flex flex-col gap-2.5 border-t border-line px-4 pt-3 pb-4">
                    <blockquote className="rounded-control bg-ground px-3 py-2.5 text-sm leading-snug">
                        “{request.question.text}”
                        <span className="mt-1 block text-[13px] text-fg-muted">
                            {request.question.asked_by}, {formatRelative(request.question.asked_at, now)}
                        </span>
                    </blockquote>
                    <Button href={`${href}#conversacion`} variant="primary" className="self-start">
                        Responder
                    </Button>
                </div>
            ) : null}
            {request.can_rate ? (
                <div className="flex items-center gap-3 border-t border-line px-4 py-3">
                    <span className="flex-1 text-sm font-semibold">¿Cómo te fue con la entrega?</span>
                    <Button variant="secondary" icon="check-circle" href={route('ai.ratings.create', { request: request.key })}>
                        Calificar
                    </Button>
                </div>
            ) : null}
        </article>
    );
}
