import { Link, router } from '@inertiajs/react';
import { MyRequestCard } from '@/Components/ai/requests/MyRequestCard';
import { Button } from '@/Components/ui/Button';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { IconButton } from '@/Components/ui/IconButton';
import { Pagination } from '@/Components/ui/Pagination';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { route } from '@/lib/route';
import type { MineFilter, RequestsMineProps } from '@/types/pages/ai-requester';
import type { PageProps } from '@/types/shared';

const FILTERS: { value: MineFilter; label: string }[] = [
    { value: 'active', label: 'Activas' },
    { value: 'done', label: 'Terminadas' },
    { value: 'all', label: 'Todas' },
];

const EMPTY_FILTERED: Record<MineFilter, string> = {
    active: 'No tienes solicitudes activas.',
    done: 'Todavía no se ha terminado ninguna de tus solicitudes.',
    all: 'No hay solicitudes.',
};

/** Mis solicitudes: what the requester asked for, where each one is and what waits for them. */
export default function RequestsMine(props: PageProps<RequestsMineProps>) {
    const { requests, filter, counts } = props;
    const createHref = route('ai.requests.create');
    const nothingYet = counts.all === 0;
    const rows = [...requests.data].sort((a, b) => Number(b.needs_reply) - Number(a.needs_reply));

    const choose = (value: MineFilter) => {
        if (value !== filter) router.get(route('ai.requests.mine'), { filter: value }, { preserveScroll: true, replace: true });
    };

    return (
        <GestorLayout shared={props} title="Mis solicitudes · Gestor IA" mode="requester">
            <div className="mx-auto flex w-full max-w-[880px] flex-col gap-4">
                <div className="flex items-center gap-3">
                    <h1 className="flex-1 font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[32px]">Mis solicitudes</h1>
                    {!nothingYet ? (
                        <>
                            <span className="md:hidden">
                                <IconButton icon="plus" label="Pedir un desarrollo o una mejora" href={createHref} className="bg-brand-button text-brand-button-ink hover:bg-brand-button hover:brightness-[0.93]" />
                            </span>
                            <span className="hidden md:inline-flex">
                                <Button icon="plus" href={createHref}>
                                    Pedir un desarrollo o una mejora
                                </Button>
                            </span>
                        </>
                    ) : null}
                </div>

                {nothingYet ? (
                    <section className="flex flex-col items-start gap-4 rounded-card border border-line bg-surface p-5 md:p-8">
                        <span aria-hidden="true" className="flex size-12 items-center justify-center rounded-icon bg-subtle">
                            <Icon name="inbox" size={22} />
                        </span>
                        <h2 className="font-display text-[22px] leading-tight font-bold tracking-[-0.02em]">Todavía no has pedido nada</h2>
                        <p className="max-w-[52ch] text-[15px] text-fg-soft">
                            Si algo te quita tiempo o una app podría hacerlo mejor, cuéntanos y TI lo revisa en máximo 3 días hábiles.
                        </p>
                        <Link
                            href={createHref}
                            className="flex w-full items-center gap-3 rounded-card bg-brand-button p-4 text-brand-button-ink no-underline hover:brightness-[0.93] sm:w-auto sm:min-w-[360px]"
                        >
                            <span aria-hidden="true" className="flex size-10 shrink-0 items-center justify-center rounded-control bg-white/15">
                                <Icon name="plus" size={20} strokeWidth={2.4} />
                            </span>
                            <span className="flex flex-col">
                                <b className="text-[15px]">Pedir un desarrollo o una mejora</b>
                                <span className="text-[13px] opacity-85">Toma unos 4 minutos</span>
                            </span>
                        </Link>
                        <Link href={route('ai.roadmap.index')} className="text-sm font-bold underline underline-offset-2">
                            Ver qué está haciendo TI en la hoja de ruta
                        </Link>
                    </section>
                ) : (
                    <>
                        <div role="group" aria-label="Filtrar" className="flex flex-wrap gap-2">
                            {FILTERS.map((option) => {
                                const pressed = option.value === filter;
                                const count = counts[option.value];
                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        aria-pressed={pressed}
                                        onClick={() => choose(option.value)}
                                        className={cx(
                                            'tabular inline-flex h-11 items-center rounded-chip border px-3.5 text-sm transition-colors duration-150',
                                            pressed ? 'border-fg bg-fg font-bold text-on-fg' : 'border-line-strong bg-surface font-semibold hover:border-fg-muted',
                                        )}
                                    >
                                        {option.value === 'all' ? option.label : `${option.label} · ${count}`}
                                    </button>
                                );
                            })}
                        </div>

                        {rows.length === 0 ? (
                            <EmptyState
                                icon="filter"
                                title={EMPTY_FILTERED[filter]}
                                actions={
                                    <Button variant="secondary" onClick={() => choose('all')}>
                                        Ver todas
                                    </Button>
                                }
                            />
                        ) : (
                            <ul className="grid gap-3 md:grid-cols-2">
                                {rows.map((request) => (
                                    <li key={request.key} className={cx('flex', request.needs_reply && 'md:col-span-2')}>
                                        <div className="flex-1 [&>article]:h-full">
                                            <MyRequestCard request={request} />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {requests.meta.last_page > 1 ? (
                            <div className="overflow-hidden rounded-card border border-line bg-surface">
                                <Pagination paginated={requests} className="border-t-0" />
                            </div>
                        ) : null}
                    </>
                )}
            </div>
        </GestorLayout>
    );
}
