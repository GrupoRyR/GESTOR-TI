import { useId } from 'react';
import { Button } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { Icon } from '@/Components/ui/Icon';
import { formatDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { TelegramState } from '@/types/pages/profile';

/** Telegram linking: linked (with "Desvincular") or an invitation to link. */
export function TelegramCard({ telegram }: { telegram: TelegramState }) {
    const titleId = useId();
    return (
        <section aria-labelledby={titleId} className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
            <div className="flex items-center gap-2.5">
                <Icon name="telegram" size={22} className="text-status-scheduled" />
                <h2 id={titleId} className="text-lg font-bold">
                    Telegram
                </h2>
                <span className="ml-auto">
                    {telegram.linked ? (
                        <Chip tone="green" icon="check" size="sm">
                            Vinculado
                        </Chip>
                    ) : (
                        <Chip tone="gray" size="sm">
                            Sin vincular
                        </Chip>
                    )}
                </span>
            </div>
            {telegram.linked ? (
                <>
                    <p className="text-sm leading-[1.45] text-fg-soft">
                        Te llegan avisos, aprobaciones con botones y el Asistente en el chat{' '}
                        <b className="font-bold text-fg">@{telegram.username}</b> desde el {formatDate(telegram.linked_at)}.
                    </p>
                    <Button
                        variant="danger"
                        href={route('profile.telegram.destroy')}
                        method="delete"
                        preserveScroll
                        className="self-start"
                    >
                        Desvincular
                    </Button>
                </>
            ) : (
                <>
                    <p className="text-sm leading-[1.45] text-fg-soft">
                        Recibe en el celular los avisos de tus solicitudes y aprueba con un toque, aunque no abras el Gestor.
                    </p>
                    <Button href={route('profile.telegram.create')} icon="telegram" className="self-start">
                        Vincular Telegram
                    </Button>
                </>
            )}
        </section>
    );
}
