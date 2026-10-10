import { Link } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { formatLongDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { RequestsSubmittedProps } from '@/types/pages/ai-requester';
import type { PageProps } from '@/types/shared';

/** "Recibimos tu solicitud": the key to remember and what happens next. */
export default function RequestsSubmitted(props: PageProps<RequestsSubmittedProps>) {
    const { request, first_response_due_at, telegram_linked } = props;

    const steps = [
        {
            title: 'TI la mira en máximo 3 días hábiles',
            text: `A más tardar el ${formatLongDate(first_response_due_at)}.`,
        },
        { title: 'Te puede hacer preguntas', text: 'Si falta algo, te escribimos y respondes desde aquí.' },
        { title: 'Si va, la aprueban y entra a la hoja de ruta', text: 'Ahí ves en qué puesto queda y cuándo empieza.' },
    ];

    return (
        <GestorLayout shared={props} title={`Recibimos ${request.key} · Gestor IA`} mode="requester">
            <div className="mx-auto flex w-full max-w-[560px] flex-col gap-6 md:pt-6">
                <div role="status" className="flex flex-col gap-3">
                    <span aria-hidden="true" className="flex size-16 items-center justify-center rounded-full bg-status-active-bg text-status-active">
                        <Icon name="check" size={32} strokeWidth={2.6} />
                    </span>
                    <h1 className="font-display text-[28px] leading-[1.1] font-bold tracking-[-0.02em] md:text-[34px]">
                        Recibimos tu solicitud <span className="tabular whitespace-nowrap">{request.key}</span>
                    </h1>
                    <p className="text-[15px] font-semibold">{request.title}</p>
                    <p className="text-[15px] text-fg-soft">Guárdate el número: con él la encuentras siempre.</p>
                </div>

                <section aria-labelledby="sigue" className="rounded-card border border-line bg-surface p-4 md:p-5">
                    <h2 id="sigue" className="mb-3 text-base font-bold">
                        Qué sigue
                    </h2>
                    <ol className="flex flex-col gap-4">
                        {steps.map((item, index) => (
                            <li key={item.title} className="grid grid-cols-[28px_minmax(0,1fr)] gap-3">
                                <span aria-hidden="true" className="tabular flex size-7 items-center justify-center rounded-full bg-subtle text-[13px] font-bold">
                                    {index + 1}
                                </span>
                                <span className="flex flex-col gap-0.5 text-sm">
                                    <b>{item.title}</b>
                                    <span className="text-fg-muted">{item.text}</span>
                                </span>
                            </li>
                        ))}
                    </ol>
                </section>

                <p className="flex items-start gap-2.5 text-sm text-fg-soft">
                    <span className="mt-0.5 shrink-0 text-fg-muted">
                        <Icon name="bell" size={16} />
                    </span>
                    <span>
                        {telegram_linked ? 'Los avances te llegan por correo y por Telegram.' : 'Los avances te llegan por correo, y por Telegram si lo vinculaste.'}{' '}
                        {!telegram_linked ? (
                            <Link href={route('profile.telegram.create')} className="font-bold text-fg underline underline-offset-2">
                                Vincular Telegram
                            </Link>
                        ) : null}
                    </span>
                </p>

                <div className="flex flex-col gap-2.5 sm:flex-row">
                    <Button size="lg" href={route('ai.requests.show', { request: request.key })} className="sm:flex-1">
                        Ver mi solicitud
                    </Button>
                    <Button size="lg" variant="secondary" href={route('home')} className="sm:flex-1">
                        Volver al inicio
                    </Button>
                </div>
            </div>
        </GestorLayout>
    );
}
