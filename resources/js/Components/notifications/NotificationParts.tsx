import type { MouseEvent } from 'react';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { IconButton } from '@/Components/ui/IconButton';
import { cx } from '@/Components/ui/utils';
import { formatNotificationTime, notificationDetail, resolveNotificationKind } from '@/lib/notifications';
import type { NotificationItem } from '@/types/pages/notifications';

export interface NotificationRowProps {
    item: NotificationItem;
    now: Date;
    /** Desktop/tablet row inside the list card, or phone card. */
    variant: 'row' | 'card';
    /** Called on a plain click of an item with `url` (the page marks it read and visits). */
    onOpen: (event: MouseEvent<HTMLAnchorElement>, item: NotificationItem, url: string) => void;
    onMarkRead: (item: NotificationItem) => void;
}

/** "Nueva" chip: unread is said in words, not only by color and weight. */
function NewChip({ compact }: { compact: boolean }) {
    return (
        <span
            className={cx(
                'inline-flex shrink-0 items-center gap-[5px] rounded-chip bg-brand-tint text-xs font-bold text-brand-ink',
                compact ? 'h-5 px-[7px]' : 'h-[22px] px-2',
            )}
        >
            <span aria-hidden="true" className={cx('rounded-full bg-brand-button', compact ? 'size-1.5' : 'size-[7px]')} />
            Nueva
        </span>
    );
}

function KindTile({ icon, unread }: { icon: IconName; unread: boolean }) {
    return (
        <span
            className={cx(
                'flex size-9 shrink-0 items-center justify-center rounded-[10px]',
                unread ? 'bg-brand-tint text-brand-ink' : 'bg-subtle text-fg-muted',
            )}
        >
            <Icon name={icon} size={18} />
        </span>
    );
}

/**
 * One notification. With `url` the content is a link (opening marks it
 * read); without it, plain text. Unread items get "Nueva", a bold title, a
 * left bar and a 44 px "Marcar como leída" button (on phone cards only when
 * there is no url: opening already marks it read).
 */
export function NotificationRow({ item, now, variant, onOpen, onMarkRead }: NotificationRowProps) {
    const kind = resolveNotificationKind(item);
    const unread = item.read_at === null;
    const url = 'url' in item.data ? item.data.url : null;
    const detail = notificationDetail(item);
    const time = formatNotificationTime(item.created_at, now);
    const card = variant === 'card';
    const showMarkRead = unread && (!card || url === null);

    const titleClass = cx(
        'min-w-0',
        card ? 'line-clamp-2 text-[15px] leading-[1.3]' : 'truncate text-sm',
        unread ? 'font-bold text-fg' : 'font-medium text-fg-soft',
    );

    const body = card ? (
        <>
            <KindTile icon={kind.icon} unread={unread} />
            <span className="flex min-w-0 flex-1 flex-col gap-[3px]">
                <span className="flex items-center gap-1.5">
                    {unread ? <NewChip compact /> : null}
                    <span className="truncate text-xs text-fg-muted">
                        {kind.module} · <time dateTime={item.created_at}>{time}</time>
                    </span>
                </span>
                <b className={titleClass}>{item.data.title}</b>
                {detail ? <span className="truncate text-[13px] text-fg-muted">{detail}</span> : null}
            </span>
        </>
    ) : (
        <>
            <KindTile icon={kind.icon} unread={unread} />
            <span className="flex min-w-0 flex-col gap-0.5">
                <span className="flex min-w-0 items-center gap-2">
                    {unread ? <NewChip compact={false} /> : null}
                    <b className={titleClass}>{item.data.title}</b>
                </span>
                <span className="truncate text-[13px] text-fg-muted">
                    {kind.module}
                    {detail ? ` · ${detail}` : null}
                </span>
            </span>
            <time dateTime={item.created_at} className="text-right text-[13px] whitespace-nowrap text-fg-muted">
                {time}
            </time>
        </>
    );

    const contentClass = card
        ? 'flex min-h-16 min-w-0 flex-1 items-start gap-3 py-3 pl-3'
        : 'grid min-h-[60px] min-w-0 flex-1 grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-x-3.5 py-2';

    const content =
        url !== null ? (
            <a
                href={url}
                onClick={(event) => onOpen(event, item, url)}
                className={cx(contentClass, 'rounded-control text-fg no-underline focus-visible:outline-offset-[-2px]')}
            >
                {body}
            </a>
        ) : (
            <div className={contentClass}>{body}</div>
        );

    const markRead = showMarkRead ? (
        <IconButton icon="check" iconSize={18} label={`Marcar como leída: ${item.data.title}`} onClick={() => onMarkRead(item)} className={card ? 'mt-2.5 mr-1' : 'text-fg-soft'} />
    ) : !card ? (
        <span aria-hidden="true" className="w-11 shrink-0" />
    ) : null;

    if (card) {
        return (
            <li
                className={cx(
                    'flex items-start gap-1 rounded-[14px] border border-line',
                    showMarkRead ? 'pr-0' : 'pr-3',
                    unread ? 'bg-surface shadow-[inset_4px_0_0_var(--color-brand-button)]' : 'bg-ground',
                )}
            >
                {content}
                {markRead}
            </li>
        );
    }

    return (
        <li
            className={cx(
                'flex items-center gap-2 border-t border-line pr-3 pl-4',
                unread ? 'bg-surface shadow-[inset_4px_0_0_var(--color-brand-button)]' : 'bg-ground',
            )}
        >
            {content}
            {markRead}
        </li>
    );
}

const KINDS: { icon: IconName; label: string; hint: string }[] = [
    { icon: 'history', label: 'Tus solicitudes', hint: 'Cuando cambian de estado, cuando TI espera tu respuesta y cuando llegan a producción.' },
    { icon: 'check-circle', label: 'Aprobaciones', hint: 'Una solicitud o un proyecto espera tu visto bueno.' },
    { icon: 'clipboard-check', label: 'Requerimientos de TI', hint: 'Un dato, un acceso o una validación que TI te pide para un proyecto.' },
    { icon: 'lock', label: 'Bóveda de credenciales', hint: 'Accesos y credenciales por vencer o por rotar.' },
    { icon: 'alert-triangle', label: 'Alertas', hint: 'Fallas o algo que se sale de lo normal.' },
];

/** "Qué te llega aquí": shown with the empty state. */
export function NotificationKinds({ className }: { className?: string }) {
    return (
        <section aria-labelledby="notification-kinds" className={cx('flex flex-col gap-3 rounded-[14px] border border-line bg-surface p-4', className)}>
            <h3 id="notification-kinds" className="text-[15px] font-bold">
                Qué te llega aquí
            </h3>
            <ul className="flex flex-col gap-3 text-sm leading-[1.4] text-fg-soft">
                {KINDS.map((kind) => (
                    <li key={kind.label} className="flex items-start gap-2.5">
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-subtle text-fg-soft">
                            <Icon name={kind.icon} size={15} />
                        </span>
                        <span>
                            <b className="font-semibold text-fg">{kind.label}</b>
                            <br />
                            {kind.hint}
                        </span>
                    </li>
                ))}
            </ul>
            <p className="text-[13px] leading-[1.45] text-fg-muted">
                Algunas dependen de tu rol: por ejemplo, las de la bóveda solo llegan a quien tiene acceso a ella.
            </p>
        </section>
    );
}
