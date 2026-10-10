import { Link } from '@inertiajs/react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import type { AiAvailability, AiUsage } from '@/types/suggestions';

export interface AiOffNoteProps {
    availability: AiAvailability;
    /** Administración › Integraciones. */
    integrationsHref: string;
    provider?: string;
    /** Cap and spend labels when the monthly cap is reached. */
    usage?: AiUsage | null;
    className?: string;
}

/**
 * One short note for admins (`ai.manage`) when suggestions are off. Renders
 * nothing when they are on (`ready`) or for everyone else (`hidden`).
 */
export function AiOffNote({ availability, integrationsHref, provider = 'DeepSeek', usage = null, className }: AiOffNoteProps) {
    if (availability !== 'off_no_key' && availability !== 'off_cap') return null;

    const reason =
        availability === 'off_no_key'
            ? `Falta la clave de ${provider}.`
            : `Se llegó al tope mensual${usage ? ` de ${usage.cap_label}` : ''}.${usage ? ` Vuelven el ${usage.resumes_on}.` : ''}`;

    return (
        <div className={cx('flex items-start gap-2.5 rounded-control bg-subtle px-3.5 py-2.5 text-[13px] leading-normal text-fg-soft', className)}>
            <Icon name="power" size={16} className="mt-0.5" />
            <span className="min-w-0 flex-1">
                <b className="text-fg">Las sugerencias están apagadas.</b> {reason}
                {availability === 'off_cap' && usage ? (
                    <span className="tabular block text-xs text-fg-muted">
                        Uso del mes: {usage.spent_label} de {usage.cap_label}
                    </span>
                ) : null}
            </span>
            <Link href={integrationsHref} className="inline-flex min-h-11 shrink-0 items-center font-bold text-fg underline underline-offset-[3px] self-center">
                Ir a Integraciones
            </Link>
        </div>
    );
}
