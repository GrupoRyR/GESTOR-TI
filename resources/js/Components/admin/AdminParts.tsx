import type { ReactNode, SelectHTMLAttributes } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';

/** Page title, one-line explanation and the page's main actions. */
export function AdminPageHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
            <div className="flex min-w-0 flex-col gap-1">
                <h1 className="font-display text-[22px] leading-tight font-bold tracking-[-0.02em] md:text-[26px]">{title}</h1>
                {description ? <p className="text-[13px] leading-snug text-fg-muted">{description}</p> : null}
            </div>
            {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
        </div>
    );
}

/**
 * Dark, locked note about the vault: `ai.vault.access` is never granted by
 * role, only to one named person. Inverted surface in light, sunken in dark.
 */
export function VaultNote({ holderName, className }: { holderName: string; className?: string }) {
    return (
        <div
            className={cx(
                'flex items-start gap-2.5 rounded-control bg-fg px-3.5 py-3 text-[13px] leading-[1.45] text-on-fg dark:border dark:border-line-strong dark:bg-ground dark:text-fg',
                className,
            )}
        >
            <span className="mt-px text-status-maintenance-dot">
                <Icon name="lock" size={16} />
            </span>
            <span>
                La bóveda no es un rol. Solo {holderName} tiene acceso, y dárselo a otra persona exige su confirmación.
            </span>
        </div>
    );
}

export interface FilterSelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> {
    /** Visible prefix, also the accessible name: "Persona". */
    label: string;
    options: { value: string; label: string }[];
}

/** Pill-shaped native select for filter bars: "Persona: todas ▾". */
export function FilterSelect({ label, options, className, ...rest }: FilterSelectProps) {
    return (
        <label
            className={cx(
                'relative inline-flex h-11 max-w-full shrink-0 items-center rounded-control border border-line-strong bg-surface pl-3 text-sm font-semibold text-fg',
                'has-focus-visible:outline-2 has-focus-visible:outline-offset-1 has-focus-visible:outline-fg hover:border-fg-muted',
                className,
            )}
        >
            <span className="shrink-0 text-fg-soft">{label}:</span>
            <select
                {...rest}
                className="h-full min-w-0 cursor-pointer appearance-none truncate bg-transparent pr-9 pl-1.5 font-semibold text-fg outline-none"
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-fg-muted">
                <Icon name="chevron-down" size={14} strokeWidth={2.2} />
            </span>
        </label>
    );
}

/** Toggle pill for boolean filters ("Solo acciones sensibles"). */
export function FilterToggle({ pressed, onChange, children }: { pressed: boolean; onChange: (pressed: boolean) => void; children: ReactNode }) {
    return (
        <button
            type="button"
            aria-pressed={pressed}
            onClick={() => onChange(!pressed)}
            className={cx(
                'inline-flex h-11 shrink-0 items-center gap-2 rounded-control border px-3 text-sm font-semibold transition-colors duration-150',
                pressed ? 'border-fg bg-fg text-on-fg' : 'border-line-strong bg-surface text-fg hover:border-fg-muted',
            )}
        >
            {pressed ? <Icon name="check" size={14} strokeWidth={2.6} /> : null}
            {children}
        </button>
    );
}

/** "Monitoreo, Tickets, Inventario y Operación". */
export function joinSpanish(items: string[]): string {
    if (items.length <= 1) return items.join('');
    return `${items.slice(0, -1).join(', ')} y ${items[items.length - 1]}`;
}
