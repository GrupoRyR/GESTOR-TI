import { router } from '@inertiajs/react';
import { useEffect, useId, useState } from 'react';
import { cx } from '@/Components/ui/utils';
import { route } from '@/lib/route';
import type { NotificationChannel, NotificationEvent } from '@/types/pages/profile';

const CHANNELS: { key: NotificationChannel; label: string; phrase: string }[] = [
    { key: 'mail', label: 'Correo', phrase: 'por correo' },
    { key: 'telegram', label: 'Telegram', phrase: 'por Telegram' },
    { key: 'database', label: 'Campana', phrase: 'en la campana' },
];

const GRID = 'md:grid md:grid-cols-[minmax(0,1fr)_96px_96px_96px] md:items-center';

export interface NotificationMatrixProps {
    events: NotificationEvent[];
    telegramLinked: boolean;
}

/**
 * "Cómo te avisamos": event × channel checkboxes that save on change.
 * Desktop: a matrix with the channel headers; phone: each event with three
 * labelled checkboxes. Telegram stays disabled until the account is linked.
 */
export function NotificationMatrix({ events, telegramLinked }: NotificationMatrixProps) {
    const titleId = useId();
    const [state, setState] = useState(events);
    const [announcement, setAnnouncement] = useState('');

    // A new visit brings the saved values.
    useEffect(() => setState(events), [events]);

    const toggle = (event: NotificationEvent, channel: (typeof CHANNELS)[number], enabled: boolean) => {
        const apply = (value: boolean) =>
            setState((current) =>
                current.map((item) => (item.key === event.key ? { ...item, channels: { ...item.channels, [channel.key]: value } } : item)),
            );
        apply(enabled);
        setAnnouncement(`Guardado: ${event.label} ${channel.phrase}, ${enabled ? 'activado' : 'desactivado'}.`);
        router.put(
            route('profile.notifications.update'),
            { event: event.key, channel: channel.key, enabled },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    apply(!enabled);
                    setAnnouncement(`No se pudo guardar ${event.label} ${channel.phrase}. Intenta de nuevo.`);
                },
            },
        );
    };

    return (
        <section aria-labelledby={titleId} className="overflow-hidden rounded-card border border-line bg-surface">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-line px-5 py-4">
                <h2 id={titleId} className="text-lg font-bold">
                    Cómo te avisamos
                </h2>
                <p className="text-[13px] text-fg-muted">Los cambios se guardan solos</p>
            </div>

            {!telegramLinked ? (
                <p className="border-b border-line bg-ground px-5 py-2.5 text-[13px] text-fg-soft">
                    Para recibir avisos por Telegram, primero vincula tu cuenta.
                </p>
            ) : null}

            <div aria-hidden="true" className={cx('hidden h-10 bg-subtle px-5 text-xs font-bold text-fg-muted', GRID)}>
                <span>Cuándo</span>
                {CHANNELS.map((channel) => (
                    <span key={channel.key} className="text-center">
                        {channel.label}
                    </span>
                ))}
            </div>

            <ul>
                {state.map((event, index) => {
                    const labelId = `${titleId}-${event.key}`;
                    return (
                        <li
                            key={event.key}
                            role="group"
                            aria-labelledby={labelId}
                            className={cx('flex flex-col gap-1 px-5 py-3 md:min-h-14 md:py-0', GRID, index > 0 && 'border-t border-line')}
                        >
                            <span className="flex flex-col gap-0.5 text-sm md:py-2">
                                <b id={labelId} className="font-semibold">
                                    {event.label}
                                </b>
                                <span className="text-xs text-fg-muted">{event.hint}</span>
                            </span>
                            <span className="flex flex-wrap gap-x-5 md:contents">
                                {CHANNELS.map((channel) => {
                                    const disabled = channel.key === 'telegram' && !telegramLinked;
                                    return (
                                        <label
                                            key={channel.key}
                                            title={disabled ? 'Vincula Telegram para activarlo' : undefined}
                                            className={cx(
                                                'flex h-11 cursor-pointer items-center gap-2 text-sm font-semibold md:justify-center',
                                                disabled && 'cursor-not-allowed opacity-50',
                                            )}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={disabled ? false : event.channels[channel.key]}
                                                disabled={disabled}
                                                onChange={(change) => toggle(event, channel, change.target.checked)}
                                                className="size-5 shrink-0 cursor-[inherit] accent-brand-button"
                                            />
                                            <span className="md:sr-only">{channel.label}</span>
                                        </label>
                                    );
                                })}
                            </span>
                        </li>
                    );
                })}
            </ul>

            <p role="status" aria-live="polite" className="sr-only">
                {announcement}
            </p>
        </section>
    );
}
