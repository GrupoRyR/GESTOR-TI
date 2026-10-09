import type { CSSProperties } from 'react';
import { brandScopeStyle } from '@/Components/ui/brandScope';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import type { CompanyColors } from '@/types/shared';
import { WIZARD_STEPS, type WizardStep } from './companyWizard';

export interface WizardStepperProps {
    current: WizardStep;
    /** Furthest step reached; earlier steps can be revisited. */
    reached: WizardStep;
    /** Steps that hold an error (client or server). */
    invalid: WizardStep[];
    onSelect: (step: WizardStep) => void;
}

/** Ordered list of the five steps; the current one carries `aria-current="step"`. */
export function WizardStepper({ current, reached, invalid, onSelect }: WizardStepperProps) {
    return (
        <nav aria-label="Pasos para agregar la empresa">
            <ol className="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
                {WIZARD_STEPS.map(({ step, label }) => {
                    const isCurrent = step === current;
                    const done = step < reached && !isCurrent;
                    const hasError = invalid.includes(step);
                    const reachable = step <= reached && !isCurrent;
                    const marker = (
                        <span
                            aria-hidden="true"
                            className={cx(
                                'flex size-7 shrink-0 items-center justify-center rounded-full text-[13px] font-bold',
                                hasError && !isCurrent
                                    ? 'bg-status-failure text-white dark:text-on-fg'
                                    : isCurrent
                                      ? 'bg-fg text-on-fg'
                                      : done
                                        ? 'bg-status-active text-white dark:text-on-fg'
                                        : 'border-[1.5px] border-line-strong text-fg-muted',
                            )}
                        >
                            {hasError && !isCurrent ? '!' : done ? <Icon name="check" size={14} strokeWidth={3} /> : step}
                        </span>
                    );
                    const text = (
                        <span className={cx('whitespace-nowrap', !isCurrent && 'max-lg:sr-only')}>
                            {label}
                            {hasError ? <span className="sr-only"> (tiene errores)</span> : done ? <span className="sr-only"> (listo)</span> : null}
                        </span>
                    );
                    const row = 'flex min-h-11 items-center gap-3 rounded-control px-2 text-sm lg:h-[52px] lg:px-3';

                    return (
                        <li key={step} aria-current={isCurrent ? 'step' : undefined} className="shrink-0">
                            {reachable ? (
                                <button
                                    type="button"
                                    onClick={() => onSelect(step)}
                                    className={cx(row, 'w-full text-left text-fg-soft hover:bg-surface')}
                                >
                                    {marker}
                                    {text}
                                </button>
                            ) : (
                                <span className={cx(row, isCurrent ? 'bg-surface font-bold text-fg' : 'text-fg-muted')}>
                                    {marker}
                                    {text}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

export interface BrandPreviewProps {
    name: string;
    colors: CompanyColors;
    className?: string;
}

/**
 * "Así lo verá su gente": the same three elements in light and dark, with
 * the button colors the Gestor derives (`brandScopeStyle`). The two cards
 * keep fixed light and dark grounds whatever the viewer's theme is.
 */
export function BrandPreview({ name, colors, className }: BrandPreviewProps) {
    const scope = brandScopeStyle(colors) as Record<string, string>;
    const letter = (name.trim() || 'E').charAt(0).toUpperCase();
    const v = (key: string): string => `var(${key})`;
    const mark = (prefix: 'light' | 'dark'): CSSProperties => ({
        background: v(`--brand-${prefix}-mark`),
        color: v(`--brand-${prefix}-mark-ink`),
    });
    const button = (prefix: 'light' | 'dark'): CSSProperties => ({
        background: v(`--brand-${prefix}-button`),
        color: v(`--brand-${prefix}-button-ink`),
    });
    const label = name.trim() || 'La empresa';

    return (
        <section aria-labelledby="vista-previa" className={cx('flex flex-col gap-3', className)} style={scope}>
            <h2 id="vista-previa" className="font-sans text-[13px] font-bold tracking-normal text-fg-muted">
                Así lo verá su gente
            </h2>
            <div className="flex flex-col gap-3 rounded-card border border-[#E5E5E0] bg-white p-[18px] text-[#17191C]">
                <div className="flex items-center gap-2.5">
                    <span
                        aria-hidden="true"
                        className="flex size-8 items-center justify-center rounded-[9px] font-display font-bold"
                        style={mark('light')}
                    >
                        {letter}
                    </span>
                    <b>{label}</b>
                </div>
                <span
                    className="flex h-11 items-center rounded-control px-3 text-sm font-bold"
                    style={{ background: v('--brand-light-tint'), color: v('--brand-light-ink') }}
                >
                    Gestor IA
                </span>
                <span className="flex h-12 items-center justify-center rounded-control px-3 text-center text-[15px] font-bold" style={button('light')}>
                    Pedir un desarrollo o una mejora
                </span>
            </div>
            <div className="flex flex-col gap-3 rounded-card border border-[#2C2E33] bg-[#121315] p-[18px] text-[#F2F2EF]">
                <div className="flex items-center gap-2.5">
                    <span
                        aria-hidden="true"
                        className="flex size-8 items-center justify-center rounded-[9px] font-display font-bold"
                        style={mark('dark')}
                    >
                        {letter}
                    </span>
                    <b>{label} · modo oscuro</b>
                </div>
                <span className="flex h-12 items-center justify-center rounded-control px-3 text-center text-[15px] font-bold" style={button('dark')}>
                    Pedir un desarrollo o una mejora
                </span>
            </div>
        </section>
    );
}
