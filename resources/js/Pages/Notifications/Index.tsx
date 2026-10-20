import { Link, router } from '@inertiajs/react';
import { useState, type MouseEvent } from 'react';
import { NotificationKinds, NotificationRow } from '@/Components/notifications/NotificationParts';
import { Button } from '@/Components/ui/Button';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon } from '@/Components/ui/Icon';
import { Pagination } from '@/Components/ui/Pagination';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { AppLayout } from '@/Layouts/AppLayout';
import { groupNotificationsByDay, isSameOrigin, visibleNotifications } from '@/lib/notifications';
import { route } from '@/lib/route';
import { useClock } from '@/lib/useClock';
import type { NotificationItem, NotificationsIndexProps } from '@/types/pages/notifications';
import type { PageProps } from '@/types/shared';

type Show = 'all' | 'unread';

const TITLE = 'Notificaciones · Gestor TI';

/** Full visit for links that leave the app; Inertia visit otherwise. */
function go(url: string) {
    if (isSameOrigin(url, window.location.origin)) {
        router.visit(url);
    } else {
        window.location.assign(url);
    }
}

function markRead(item: NotificationItem, onSuccess?: () => void) {
    router.post(route('notifications.read'), { id: item.id }, { preserveScroll: true, onSuccess });
}

/**
 * Opening an item: unread → mark read, then visit; read → visit. Modified
 * clicks (new tab) keep the browser default and mark read in the background.
 */
function openNotification(event: MouseEvent<HTMLAnchorElement>, item: NotificationItem, url: string) {
    const unread = item.read_at === null;
    const modified = event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0;

    if (modified) {
        if (unread) markRead(item);
        return;
    }

    event.preventDefault();
    if (unread) {
        markRead(item, () => go(url));
    } else {
        go(url);
    }
}

function ProfileLink({ compact = false }: { compact?: boolean }) {
    if (compact) {
        return (
            <Link
                href={route('profile.edit')}
                aria-label="Cambiar mis avisos"
                title="Cambiar mis avisos"
                className="-mr-2.5 ml-auto flex size-11 shrink-0 items-center justify-center rounded-control text-fg hover:bg-subtle"
            >
                <Icon name="filter" size={20} />
            </Link>
        );
    }
    return (
        <Button variant="secondary" icon="filter" href={route('profile.edit')} className="self-start">
            Cambiar mis avisos
        </Button>
    );
}

interface ToggleProps {
    show: Show;
    unreadTotal: number;
    onChange: (show: Show) => void;
}

function ShowToggle({ show, unreadTotal, onChange }: ToggleProps) {
    return (
        <SegmentedControl<Show>
            label="Mostrar"
            size="sm"
            value={show}
            onChange={onChange}
            options={[
                { value: 'all', label: 'Todas' },
                { value: 'unread', label: unreadTotal > 0 ? `Sin leer · ${unreadTotal}` : 'Sin leer' },
            ]}
        />
    );
}

function PhoneHeader({ toggle, unreadTotal, hasItems }: { toggle: ToggleProps; unreadTotal: number; hasItems: boolean }) {
    return (
        <header className="flex flex-col gap-2.5 border-b border-line bg-surface px-4 pt-2 pb-3">
            <div className="flex items-center gap-2">
                <Link
                    href={route('home')}
                    aria-label="Volver al inicio"
                    className="-ml-3 flex size-11 shrink-0 items-center justify-center rounded-control text-fg hover:bg-subtle"
                >
                    <Icon name="chevron-left" size={20} />
                </Link>
                <h1 className="font-display text-[22px] leading-tight font-bold">Notificaciones</h1>
                {hasItems ? <ProfileLink compact /> : null}
            </div>
            <div className="flex items-center gap-2">
                <ShowToggle {...toggle} />
                {unreadTotal > 0 ? (
                    <Link
                        href={route('notifications.read_all')}
                        method="post"
                        as="button"
                        preserveScroll
                        className="ml-auto h-11 px-1 text-sm font-bold text-fg underline underline-offset-[3px]"
                    >
                        Marcar todas leídas
                    </Link>
                ) : null}
            </div>
        </header>
    );
}

function unreadLine(count: number): string {
    if (count === 0) return 'Estás al día · las más recientes primero';
    return `${count.toLocaleString('es-CO')} sin leer · las más recientes primero`;
}

/** A7: the bell. Notifications grouped by day, unread first-class, mark read. */
export default function NotificationsIndex(props: PageProps<NotificationsIndexProps>) {
    const { notifications, filters, notifications_unread: unreadTotal } = props;
    const now = useClock({ startAt: props.now, intervalMs: 60_000 });
    const serverFilter = filters !== undefined;
    const [localShow, setLocalShow] = useState<Show>('all');
    const show: Show = serverFilter ? (filters.unread ? 'unread' : 'all') : localShow;

    const changeShow = (next: Show) => {
        if (serverFilter) {
            router.get(route('notifications.index'), next === 'unread' ? { unread: 1 } : {}, { preserveScroll: true, replace: true });
        } else {
            setLocalShow(next);
        }
    };

    const items = visibleNotifications(notifications.data, show === 'unread' && !serverFilter);
    const groups = groupNotificationsByDay(items, now);
    const nothingAtAll = notifications.data.length === 0 && show === 'all' && notifications.meta.total === 0;
    const toggle: ToggleProps = { show, unreadTotal, onChange: changeShow };
    const multiPage = notifications.meta.last_page > 1;

    // Client-side filter: unread items may live on other pages.
    const unreadElsewhere = unreadTotal > 0 && !serverFilter;
    const caughtUp = (
        <EmptyState
            bordered={false}
            icon="check-circle"
            title={unreadElsewhere ? 'Nada sin leer en esta página' : 'Estás al día'}
            description={
                unreadElsewhere
                    ? `Tienes ${unreadTotal.toLocaleString('es-CO')} sin leer en otras páginas.`
                    : 'No tienes notificaciones sin leer.'
            }
            actions={
                <Button variant="secondary" onClick={() => changeShow('all')}>
                    Ver todas
                </Button>
            }
        />
    );

    const emptyAll = (
        <div className="flex flex-col gap-5">
            <EmptyState
                bordered={false}
                icon="bell"
                title="No tienes notificaciones"
                description="Cuando algo necesite tu atención, aparece aquí y en el número de la campana."
            />
            <NotificationKinds />
            <div className="md:hidden">
                <Button variant="secondary" icon="filter" href={route('profile.edit')} fullWidth>
                    Cambiar mis avisos
                </Button>
            </div>
        </div>
    );

    return (
        <AppLayout
            shared={props}
            title={TITLE}
            phoneHeader={<PhoneHeader toggle={toggle} unreadTotal={unreadTotal} hasItems={!nothingAtAll} />}
        >
            <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                <div className="flex min-w-0 flex-col gap-4">
                    <div className="flex flex-wrap items-end justify-between gap-4 max-md:hidden">
                        <div className="flex flex-col gap-1">
                            <h1 className="font-display text-[30px] leading-tight font-bold tracking-[-0.02em]">Notificaciones</h1>
                            {!nothingAtAll ? <p className="text-sm text-fg-muted">{unreadLine(unreadTotal)}</p> : null}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <span className="xl:hidden">
                                <ProfileLink />
                            </span>
                            {unreadTotal > 0 ? (
                                <Button variant="secondary" icon="check" href={route('notifications.read_all')} method="post" preserveScroll>
                                    Marcar todas como leídas
                                </Button>
                            ) : null}
                        </div>
                    </div>

                    <div className="max-md:hidden">
                        <ShowToggle {...toggle} />
                    </div>

                    {nothingAtAll ? (
                        emptyAll
                    ) : (
                        <>
                            {/* Tablet and desktop: one card with day headers */}
                            <section aria-label="Lista de notificaciones" className="overflow-hidden rounded-card border border-line bg-surface max-md:hidden">
                                {groups.length === 0 ? (
                                    <div className="p-6">{caughtUp}</div>
                                ) : (
                                    groups.map((group) => (
                                        <div key={group.key}>
                                            <h2 className="flex h-[34px] items-center bg-subtle px-5 text-xs font-bold text-fg-muted">{group.label}</h2>
                                            <ul>
                                                {group.items.map((item) => (
                                                    <NotificationRow
                                                        key={item.id}
                                                        item={item}
                                                        now={now}
                                                        variant="row"
                                                        onOpen={openNotification}
                                                        onMarkRead={(entry) => markRead(entry)}
                                                    />
                                                ))}
                                            </ul>
                                        </div>
                                    ))
                                )}
                                {multiPage ? (
                                    <Pagination paginated={notifications} />
                                ) : groups.length > 0 ? (
                                    <p className="flex h-12 items-center border-t border-line px-5 text-[13px] text-fg-muted">Eso es todo por ahora.</p>
                                ) : null}
                            </section>

                            {/* Phone: cards per day */}
                            <section aria-label="Lista de notificaciones" className="-mt-1 flex flex-col md:hidden">
                                {groups.length === 0 ? (
                                    <div className="mt-3 rounded-card border border-line bg-surface p-5">{caughtUp}</div>
                                ) : (
                                    groups.map((group) => (
                                        <div key={group.key}>
                                            <h2 className="px-0.5 pt-3 pb-1.5 text-[13px] font-bold text-fg-muted">{group.label}</h2>
                                            <ul className="flex flex-col gap-2">
                                                {group.items.map((item) => (
                                                    <NotificationRow
                                                        key={item.id}
                                                        item={item}
                                                        now={now}
                                                        variant="card"
                                                        onOpen={openNotification}
                                                        onMarkRead={(entry) => markRead(entry)}
                                                    />
                                                ))}
                                            </ul>
                                        </div>
                                    ))
                                )}
                                {multiPage ? (
                                    <div className="mt-3 overflow-hidden rounded-card border border-line bg-surface">
                                        <Pagination paginated={notifications} className="border-t-0" />
                                    </div>
                                ) : null}
                            </section>
                        </>
                    )}
                </div>

                <aside className="flex flex-col gap-4 max-xl:hidden">
                    <section className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
                        <h2 className="font-display text-lg font-bold">Cómo te avisamos</h2>
                        <p className="text-sm leading-normal text-fg-soft">Tú eliges qué te llega por correo, por Telegram o solo a la campana.</p>
                        <p className="text-[13px] leading-[1.45] text-fg-muted">
                            Las alertas críticas y los accesos a la bóveda siempre llegan aquí, aunque los quites.
                        </p>
                        <ProfileLink />
                    </section>
                    <section className="flex flex-col gap-2 rounded-card border border-dashed border-line-strong px-5 py-4 text-[13px] leading-[1.45] text-fg-soft">
                        <h2 className="text-sm font-bold text-fg">Al abrir una notificación</h2>
                        <p>Te lleva a lo que la generó y queda marcada como leída.</p>
                    </section>
                </aside>
            </div>
        </AppLayout>
    );
}
