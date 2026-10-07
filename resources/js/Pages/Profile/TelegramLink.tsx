import { Link, router } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { expiryText, minutesLeft } from '@/Components/profile/telegramExpiry';
import { Button } from '@/Components/ui/Button';
import { StateTile } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { AppLayout } from '@/Layouts/AppLayout';
import { route } from '@/lib/route';
import type { TelegramLinkProps } from '@/types/pages/profile';
import type { PageProps } from '@/types/shared';

const POLL_MS = 4_000;
const CLOCK_MS = 15_000;

function Step({ number, current, children }: { number: number; current: boolean; children: ReactNode }) {
    return (
        <li className="flex gap-3 rounded-[14px] border border-line bg-surface p-4 text-[15px]">
            <span
                aria-hidden="true"
                className={cx(
                    'flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-bold',
                    current ? 'bg-fg text-on-fg' : 'border-[1.5px] border-line-strong',
                )}
            >
                {number}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-3">{children}</div>
        </li>
    );
}

/** Three steps to link Telegram; the page refreshes itself until the bot confirms. */
export default function TelegramLink(props: PageProps<TelegramLinkProps>) {
    const { link_url, expires_at, status } = props;
    const [now, setNow] = useState(() => new Date());
    const minutes = minutesLeft(expires_at, now);
    const expired = status === 'expired' || (status === 'waiting' && minutes === 0);
    const waiting = status === 'waiting' && !expired;

    // Keep the countdown honest.
    useEffect(() => {
        if (!waiting) return;
        const timer = setInterval(() => setNow(new Date()), CLOCK_MS);
        return () => clearInterval(timer);
    }, [waiting]);

    // Ask the server whether the bot confirmed (partial reload, status only).
    useEffect(() => {
        if (!waiting) return;
        const timer = setInterval(() => router.reload({ only: ['status'] }), POLL_MS);
        return () => clearInterval(timer);
    }, [waiting]);

    return (
        <AppLayout shared={props} title="Vincular Telegram · Gestor TI">
            <div className="mx-auto flex w-full max-w-[560px] flex-col gap-5">
                <div className="flex flex-col gap-1">
                    <Link
                        href={route('profile.edit')}
                        className="-ml-1 inline-flex min-h-11 items-center gap-1.5 self-start pr-2 text-sm font-semibold no-underline hover:underline"
                    >
                        <Icon name="chevron-left" size={18} />
                        Volver al perfil
                    </Link>
                    <h1 className="text-[26px] leading-tight font-bold md:text-[30px]">Vincular Telegram</h1>
                </div>

                {status === 'linked' ? (
                    <section className="flex flex-col items-start gap-3.5 rounded-card border border-line bg-surface p-6 md:p-8">
                        <span className="flex size-[52px] items-center justify-center rounded-icon bg-status-active-bg text-status-active">
                            <Icon name="check-circle" size={24} />
                        </span>
                        <h2 className="text-xl leading-tight font-bold md:text-2xl">Listo, Telegram quedó vinculado</h2>
                        <p className="text-[15px] leading-normal text-fg-soft">
                            Desde ahora te llegan los avisos al chat del bot. Puedes elegir cuáles en tu perfil.
                        </p>
                        <Button variant="dark" href={route('profile.edit')}>
                            Volver al perfil
                        </Button>
                    </section>
                ) : (
                    <>
                        <p className="text-base leading-normal text-fg-soft">
                            Así te llegan los avisos de tus solicitudes al celular, aunque no abras el Gestor.
                        </p>

                        <ol className={cx('flex flex-col gap-3', expired && 'opacity-60')}>
                            <Step number={1} current={waiting}>
                                <b className="font-bold">Abre el bot del Gestor</b>
                                {waiting ? (
                                    <a
                                        href={link_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex h-[52px] w-full items-center justify-center gap-2.5 rounded-control bg-status-scheduled text-[15px] font-bold text-white no-underline transition-[filter] duration-150 hover:brightness-[0.93] dark:text-on-fg"
                                    >
                                        <Icon name="telegram" size={18} />
                                        Abrir Telegram
                                        <span className="sr-only"> (se abre en otra pestaña)</span>
                                    </a>
                                ) : null}
                            </Step>
                            <Step number={2} current={false}>
                                <span className="flex flex-col gap-1">
                                    <b className="font-bold">Toca Iniciar en el chat</b>
                                    <span className="text-sm text-fg-soft">El bot te responde con tu nombre para confirmar.</span>
                                </span>
                            </Step>
                            <Step number={3} current={false}>
                                <span className="flex flex-col gap-1">
                                    <b className="font-bold">Vuelve aquí</b>
                                    <span className="text-sm text-fg-soft">Esta pantalla se actualiza sola.</span>
                                </span>
                            </Step>
                        </ol>

                        {waiting ? (
                            <div
                                role="status"
                                className="flex items-center gap-3 rounded-control bg-status-maintenance-bg p-3.5 text-sm leading-[1.4] text-status-maintenance"
                            >
                                <Icon name="hourglass" size={18} />
                                <span>Esperando a que toques Iniciar. {expiryText(minutes)}</span>
                            </div>
                        ) : (
                            <section
                                role="alert"
                                className="flex flex-col items-start gap-3 rounded-card border border-status-failure-line bg-surface p-5"
                            >
                                <StateTile icon="hourglass" tone="danger" />
                                <h2 className="text-lg font-bold">El enlace venció</h2>
                                <p className="text-sm leading-normal text-fg-soft">
                                    Por seguridad, cada enlace dura pocos minutos. Genera otro y repite los pasos.
                                </p>
                                <Button href={route('profile.telegram.store')} method="post" icon="refresh">
                                    Generar otro enlace
                                </Button>
                            </section>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
