import { Link } from '@inertiajs/react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { route } from '@/lib/route';
import { AssistantTile } from '../ChatBubbles';
import { SuggestionChips } from '../SourceList';

export interface ChatGreetingProps {
    /** "Jhon". */
    firstName: string;
    suggestions: string[];
    onPick?: (text: string) => void;
    disabled?: boolean;
    className?: string;
}

/** Empty conversation: greeting and suggested questions in two columns (C-ChatStates "Primera vez"). */
export function ChatGreeting({ firstName, suggestions, onPick, disabled = false, className }: ChatGreetingProps) {
    return (
        <div className={cx('my-auto flex w-full max-w-[560px] flex-col gap-3 self-center md:gap-4', className)}>
            <div className="flex items-start gap-2.5">
                <AssistantTile />
                <div className="flex flex-col gap-1">
                    <h2 className="font-display text-lg leading-tight font-bold tracking-[-0.02em] md:text-xl">Hola, {firstName}</h2>
                    <p className="text-[13px] leading-[1.45] text-fg-soft md:text-sm">
                        Pregúntame por sistemas, informes, flujos de datos y procedimientos. Siempre te digo de qué ficha saqué la respuesta.
                    </p>
                </div>
            </div>
            <SuggestionChips suggestions={suggestions} onPick={onPick} title={null} layout="grid" disabled={disabled} />
        </div>
    );
}

export interface AssistantOffNoticeProps {
    /** No AI key or monthly cap reached. */
    reason: 'off_no_key' | 'off_cap';
    /** Admin view: why, and the link to Administración › Integraciones. */
    admin: boolean;
    /** "US$ 20" (cap reason only). */
    monthlyCap?: string | null;
    className?: string;
}

/** Assistant off (C-ChatStates "Asistente apagado"): admins see why and where to fix it; everyone else a plain line. */
export function AssistantOffNotice({ reason, admin, monthlyCap = null, className }: AssistantOffNoticeProps) {
    if (!admin) {
        return (
            <div role="status" className={cx('flex w-full max-w-[560px] items-start gap-2.5 self-center rounded-card border border-line bg-surface p-3.5 md:my-auto', className)}>
                <span aria-hidden="true" className="flex size-8 shrink-0 items-center justify-center rounded-chip bg-status-local-bg text-status-local">
                    <Icon name="power" size={16} />
                </span>
                <span className="flex flex-col gap-0.5">
                    <b className="text-[15px]">El asistente no está activo.</b>
                    <span className="text-[13px] leading-[1.45] text-fg-soft">El resto del Gestor TI sigue funcionando.</span>
                </span>
            </div>
        );
    }

    const why =
        reason === 'off_cap'
            ? `Se alcanzó el tope mensual de uso de la IA${monthlyCap ? ` (${monthlyCap})` : ''}. El resto del Gestor TI sigue funcionando.`
            : 'No hay una llave de IA configurada. El resto del Gestor TI sigue funcionando.';

    return (
        <div className={cx('flex w-full max-w-[560px] flex-col gap-2.5 self-center md:my-auto', className)}>
            <div role="status" className="flex flex-col gap-2 rounded-card border border-line bg-surface p-3.5">
                <div className="flex items-center gap-2.5">
                    <span aria-hidden="true" className="flex size-8 shrink-0 items-center justify-center rounded-chip bg-status-local-bg text-status-local">
                        <Icon name="power" size={16} />
                    </span>
                    <b className="text-[15px]">El asistente está apagado</b>
                </div>
                <p className="text-[13px] leading-[1.45] text-fg-soft">{why}</p>
                <Link
                    href={route('admin.integrations.index')}
                    className="flex min-h-11 items-center justify-between gap-2 rounded-control border border-line-strong px-3 text-sm font-bold text-fg hover:bg-subtle"
                >
                    Administración › Integraciones
                    <Icon name="chevron-right" size={18} />
                </Link>
            </div>
            <p className="rounded-control border-[1.5px] border-dashed border-line-strong px-3 py-2.5 text-[13px] text-fg-muted">
                Los demás ven: <b className="text-fg">El asistente no está activo.</b>
            </p>
        </div>
    );
}
