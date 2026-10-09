import { Link } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type CSSProperties, type KeyboardEvent } from 'react';
import { firstName } from '@/Components/ai/triage/triage';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { route } from '@/lib/route';
import type { RequestSummary } from '@/types/ai';
import { CONVERTIBLE } from './ConvertToProjectDialog';
import type { RequestAction } from './RequestActionDialogs';

export interface RequestActionMenuProps {
    request: RequestSummary;
    onAction: (action: RequestAction) => void;
    className?: string;
}

interface Item {
    key: string;
    icon: IconName;
    title: string;
    detail: string;
    danger?: boolean;
    href?: string;
    action?: RequestAction;
}

const MENU_WIDTH = 300;
const MENU_HEIGHT = 340;

/**
 * Row "⋯" menu of the inbox. A native popover in the top layer, so the
 * table's scroll box never clips it; Esc and a click outside close it,
 * arrows move between items and focus goes back to the button.
 */
export function RequestActionMenu({ request, onAction, className }: RequestActionMenuProps) {
    const buttonRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState<CSSProperties>({});
    const menuId = useId();
    const name = firstName(request.requester.name);

    const items: Item[] = [
        { key: 'show', icon: 'edit', title: 'Evaluar', detail: 'Abre el detalle con el panel de evaluación', href: route('ai.requests.show', { request: request.key }) },
        { key: 'ask', icon: 'question', title: 'Pedir información', detail: `Le llega a ${name} por correo y Telegram`, action: 'ask' },
        { key: 'reject', icon: 'x-circle', title: 'Rechazar', detail: `Con motivo y mensaje a ${name}`, action: 'reject', danger: true },
        {
            key: 'merge',
            icon: 'pull-request',
            title: 'Unir con otra',
            detail: request.similar_to ? `Se parece a ${request.similar_to.key} · ${request.similar_to.votes} personas` : 'Busca la solicitud que se queda',
            action: 'merge',
        },
        {
            key: 'convert',
            icon: 'code',
            title: 'Convertir en proyecto',
            detail: CONVERTIBLE.has(request.status) ? 'Crea el proyecto con su tablero' : 'Necesita aprobación con estimado',
            action: 'convert',
        },
    ];

    useEffect(() => {
        const menu = menuRef.current;
        if (!menu) return;
        const onToggle = (event: Event) => {
            const isOpen = (event as ToggleEvent).newState === 'open';
            setOpen(isOpen);
            if (isOpen) {
                menu.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
            } else if (menu.contains(document.activeElement) || document.activeElement === document.body) {
                buttonRef.current?.focus();
            }
        };
        const hide = () => {
            if (menu.matches(':popover-open')) menu.hidePopover();
        };
        menu.addEventListener('toggle', onToggle);
        window.addEventListener('resize', hide);
        window.addEventListener('scroll', hide, true);
        return () => {
            menu.removeEventListener('toggle', onToggle);
            window.removeEventListener('resize', hide);
            window.removeEventListener('scroll', hide, true);
        };
    }, []);

    const toggle = () => {
        const menu = menuRef.current;
        const button = buttonRef.current;
        if (!menu || !button) return;
        if (menu.matches(':popover-open')) {
            menu.hidePopover();
            return;
        }
        const rect = button.getBoundingClientRect();
        const below = rect.bottom + 4 + MENU_HEIGHT <= window.innerHeight;
        setPosition({
            inset: 'auto',
            margin: 0,
            position: 'fixed',
            width: Math.min(MENU_WIDTH, window.innerWidth - 16),
            left: Math.max(8, Math.min(rect.right - MENU_WIDTH, window.innerWidth - MENU_WIDTH - 8)),
            ...(below ? { top: rect.bottom + 4 } : { bottom: window.innerHeight - rect.top + 4 }),
        });
        menu.showPopover();
    };

    const close = () => menuRef.current?.hidePopover();

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const entries = Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
        const index = entries.indexOf(document.activeElement as HTMLElement);
        const focus = (next: number) => entries[(next + entries.length) % entries.length]?.focus();
        if (event.key === 'ArrowDown') focus(index + 1);
        else if (event.key === 'ArrowUp') focus(index - 1);
        else if (event.key === 'Home') focus(0);
        else if (event.key === 'End') focus(entries.length - 1);
        else if (event.key === 'Tab') close();
        else return;
        if (event.key !== 'Tab') event.preventDefault();
    };

    const itemClass = (danger?: boolean) =>
        cx(
            'flex w-full items-start gap-3 rounded-control px-2.5 py-2 text-left no-underline outline-none hover:bg-subtle focus-visible:bg-subtle',
            danger ? 'text-status-failure' : 'text-fg',
        );

    return (
        <>
            <button
                ref={buttonRef}
                type="button"
                aria-label={`Acciones de ${request.key}`}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={menuId}
                onClick={toggle}
                className={cx(
                    'flex size-11 shrink-0 items-center justify-center rounded-control text-fg transition-colors duration-150 hover:bg-subtle',
                    open && 'border border-line-strong bg-surface',
                    className,
                )}
            >
                <Icon name="more" size={20} />
            </button>
            <div
                ref={menuRef}
                id={menuId}
                popover="auto"
                role="menu"
                aria-label={`Acciones de ${request.key}`}
                onKeyDown={onKeyDown}
                style={position}
                className="rounded-card border border-line bg-surface p-1.5 text-fg shadow-[0_12px_32px_rgb(23_25_28/0.18)]"
            >
                <p className="truncate px-2.5 pt-1 pb-1.5 text-xs text-fg-muted">
                    {request.key} · {request.requester.name}
                </p>
                {items.map((item) => {
                    const body = (
                        <>
                            <span
                                aria-hidden="true"
                                className={cx('flex size-8 shrink-0 items-center justify-center rounded-[10px]', item.danger ? 'bg-status-failure-bg' : 'bg-subtle')}
                            >
                                <Icon name={item.icon} size={16} />
                            </span>
                            <span className="flex min-w-0 flex-col text-sm leading-snug">
                                <b>{item.title}</b>
                                <span className="text-xs text-fg-muted">{item.detail}</span>
                            </span>
                        </>
                    );
                    return item.href ? (
                        <Link key={item.key} href={item.href} role="menuitem" tabIndex={-1} onClick={close} className={itemClass(item.danger)}>
                            {body}
                        </Link>
                    ) : (
                        <button
                            key={item.key}
                            type="button"
                            role="menuitem"
                            tabIndex={-1}
                            onClick={() => {
                                // Focus the trigger first so the dialog returns focus to it.
                                buttonRef.current?.focus();
                                close();
                                if (item.action) onAction(item.action);
                            }}
                            className={itemClass(item.danger)}
                        >
                            {body}
                        </button>
                    );
                })}
            </div>
        </>
    );
}
