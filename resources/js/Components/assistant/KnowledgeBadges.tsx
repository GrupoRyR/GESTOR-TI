import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { KNOWLEDGE_TYPES, reviewLabel, reviewStatus } from '@/lib/knowledge';
import type { KnowledgeType, KnowledgeVisibility, ReviewStatus } from '@/types/assistant';

// ---- type icon --------------------------------------------------------------

export type KnowledgeTypeIconSize = 'sm' | 'md' | 'lg' | 'xl';

const TILE: Record<KnowledgeTypeIconSize, { box: string; icon: number }> = {
    sm: { box: 'size-8', icon: 16 },
    md: { box: 'size-9', icon: 18 },
    lg: { box: 'size-10', icon: 20 },
    xl: { box: 'size-11', icon: 22 },
};

export interface KnowledgeTypeIconProps {
    type: KnowledgeType;
    /** sm 32 · md 36 (default) · lg 40 · xl 44 px. */
    size?: KnowledgeTypeIconSize;
    /** Corner radius: `chip` 10 px (sources, default for sm) or `icon` 14 px (lists, headers). */
    shape?: 'chip' | 'icon';
    /** Announce the type ("Informe") when no text next to it names it. */
    labelled?: boolean;
    className?: string;
}

/** Neutral tile with the type icon. Types never use status colors. */
export function KnowledgeTypeIcon({ type, size = 'md', shape = size === 'sm' ? 'chip' : 'icon', labelled = false, className }: KnowledgeTypeIconProps) {
    const info = KNOWLEDGE_TYPES[type];
    const tile = TILE[size];
    return (
        <span
            title={info.label}
            className={cx('flex shrink-0 items-center justify-center bg-subtle text-fg-soft', tile.box, shape === 'chip' ? 'rounded-chip' : 'rounded-icon', className)}
            {...(labelled ? { role: 'img', 'aria-label': info.label } : { 'aria-hidden': true })}
        >
            <Icon name={info.icon} size={tile.icon} />
        </span>
    );
}

// ---- visibility -------------------------------------------------------------

export interface VisibilityBadgeProps {
    visibility: KnowledgeVisibility;
    /** sm 24 px (phone rows, sources) · md 26 px (default). */
    size?: 'sm' | 'md';
    className?: string;
}

/** "Solo TI" (outlined, lock) or "Toda la empresa" (filled, people). Icon + text, never color alone. */
export function VisibilityBadge({ visibility, size = 'md', className }: VisibilityBadgeProps) {
    const it = visibility === 'it';
    return (
        <span
            className={cx(
                'inline-flex shrink-0 items-center gap-1.5 rounded-chip font-semibold whitespace-nowrap',
                size === 'sm' ? 'h-6 gap-1 px-2 text-xs' : 'h-[26px] px-2.5 text-[13px]',
                it ? 'border border-line-strong bg-surface text-fg' : 'bg-subtle text-fg-soft',
                className,
            )}
        >
            <Icon name={it ? 'lock' : 'employees'} size={size === 'sm' ? 12 : 14} />
            {it ? 'Solo TI' : 'Toda la empresa'}
        </span>
    );
}

// ---- review date ------------------------------------------------------------

export interface ReviewDateLabelProps {
    /** `YYYY-MM-DD`. */
    reviewAt: string;
    /** Today's Bogotá date, `YYYY-MM-DD` (from the page props). */
    today: string;
    /** Server status; computed from the dates when omitted. */
    status?: ReviewStatus;
    className?: string;
}

/**
 * "Revisar 15 ene 2027" in plain text; due soon adds a clock; overdue is an
 * amber chip with the warning icon and "Vencida 30 sep" (icon + text).
 */
export function ReviewDateLabel({ reviewAt, today, status, className }: ReviewDateLabelProps) {
    const level = status ?? reviewStatus(reviewAt, today);
    const text = reviewLabel(reviewAt, today);

    if (level === 'overdue') {
        return (
            <span
                className={cx(
                    'inline-flex h-[26px] shrink-0 items-center gap-1.5 rounded-chip bg-status-maintenance-bg px-2.5 text-[13px] font-bold whitespace-nowrap text-status-maintenance',
                    className,
                )}
            >
                <Icon name="alert-triangle" size={14} />
                {text}
                <span className="sr-only"> (revisión vencida)</span>
            </span>
        );
    }

    return (
        <span
            className={cx(
                'inline-flex items-center gap-1.5 text-[13px] whitespace-nowrap',
                level === 'due_soon' ? 'font-semibold text-status-maintenance' : 'text-fg-soft',
                className,
            )}
        >
            {level === 'due_soon' ? <Icon name="clock" size={14} /> : null}
            {text}
            {level === 'due_soon' ? <span className="sr-only"> (vence pronto)</span> : null}
        </span>
    );
}

// ---- import stepper ---------------------------------------------------------

export type ImportStep = 1 | 2 | 3;

export const IMPORT_STEPS: { step: ImportStep; label: string }[] = [
    { step: 1, label: 'Archivo' },
    { step: 2, label: 'Columnas' },
    { step: 3, label: 'Vista previa' },
];

export interface ImportStepperProps {
    current: ImportStep;
    className?: string;
}

/** Archivo · Columnas · Vista previa. Done steps show a check; the current one carries `aria-current="step"`. */
export function ImportStepper({ current, className }: ImportStepperProps) {
    return (
        <ol aria-label="Pasos de la importación" className={cx('flex flex-wrap items-center gap-x-2 gap-y-2 text-sm md:gap-x-2.5', className)}>
            {IMPORT_STEPS.map(({ step, label }, index) => {
                const done = step < current;
                const isCurrent = step === current;
                return (
                    <li key={step} aria-current={isCurrent ? 'step' : undefined} className="flex items-center gap-2 md:gap-2.5">
                        {index > 0 ? <span aria-hidden="true" className={cx('h-0.5 w-5 md:w-8', step <= current ? 'bg-fg' : 'bg-line-strong')} /> : null}
                        <span className={cx('flex items-center gap-2', isCurrent ? 'font-bold text-fg' : done ? 'text-fg-soft' : 'text-fg-muted')}>
                            <span
                                aria-hidden="true"
                                className={cx(
                                    'tabular flex size-7 shrink-0 items-center justify-center rounded-full text-[13px] font-bold',
                                    done
                                        ? 'bg-status-active text-white dark:text-on-fg'
                                        : isCurrent
                                          ? 'bg-fg text-on-fg'
                                          : 'border-[1.5px] border-line-strong text-fg-muted',
                                )}
                            >
                                {done ? <Icon name="check" size={14} strokeWidth={3} /> : step}
                            </span>
                            {label}
                            {done ? <span className="sr-only"> (listo)</span> : null}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
