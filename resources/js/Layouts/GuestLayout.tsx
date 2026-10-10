import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { FlashMessages } from '@/Components/ui/FlashMessages';
import { cx } from '@/Components/ui/utils';
import { PHASE_LABELS, type Phase } from '@/lib/status';
import type { Flash } from '@/types/shared';

export interface GuestLayoutProps {
    title: string;
    /** Bottom of the left column, e.g. the emergency-access link. */
    footer?: ReactNode;
    /** Right column on desktop; defaults to the five request phases. Pass `null` to hide. */
    aside?: ReactNode | null;
    flash?: Flash;
    children: ReactNode;
}

const PHASES: Phase[] = [1, 2, 3, 4, 5];
const CURRENT_PHASE: Phase = 3;

/** Default right column: what the requester gets, told through the five phases. */
function PhaseShowcase() {
    return (
        <div className="flex flex-col gap-9">
            <p className="max-w-[460px] text-[15px] leading-normal text-fg-soft">
                Todo lo que pides a TI queda aquí, y ves en qué va sin preguntar.
            </p>
            <ol className="flex flex-col gap-3.5">
                {PHASES.map((phase) => (
                    <li key={phase} className="grid grid-cols-[minmax(0,220px)_minmax(0,1fr)] items-center gap-6">
                        <span
                            aria-hidden="true"
                            className={cx(
                                'h-3.5 rounded-[7px]',
                                phase < CURRENT_PHASE && 'bg-fg',
                                phase === CURRENT_PHASE && 'bg-status-scheduled',
                                phase > CURRENT_PHASE && 'bg-line-strong',
                            )}
                        />
                        <span
                            className={cx(
                                'font-display text-[30px] leading-tight font-bold tracking-[-0.02em]',
                                phase > CURRENT_PHASE && 'text-fg-muted',
                            )}
                        >
                            {PHASE_LABELS[phase]}
                        </span>
                    </li>
                ))}
            </ol>
        </div>
    );
}

/**
 * Neutral shell for sign-in pages (no company brand yet): content column
 * on the left, phases on the right from 1024 px.
 */
export function GuestLayout({ title, footer, aside, flash, children }: GuestLayoutProps) {
    const asideContent = aside === undefined ? <PhaseShowcase /> : aside;

    return (
        <div className="min-h-dvh bg-surface text-fg lg:grid lg:grid-cols-[minmax(0,600px)_minmax(0,1fr)]">
            <Head title={title} />
            <main className="flex min-h-dvh flex-col justify-between gap-10 px-6 pt-8 pb-7 sm:px-12 lg:px-[88px] lg:py-16">
                <div className="flex items-center gap-2.5">
                    <span
                        aria-hidden="true"
                        className="flex size-[34px] items-center justify-center rounded-[10px] bg-fg font-display text-sm font-bold text-on-fg lg:size-9 lg:text-[15px]"
                    >
                        TI
                    </span>
                    <span className="text-[15px] font-bold lg:text-base">Gestor TI · Grupo RYR</span>
                </div>

                <div className="flex w-full max-w-[424px] flex-col gap-6 lg:gap-7">
                    {flash ? <FlashMessages flash={flash} /> : null}
                    {children}
                </div>

                <div className="text-[13px] text-fg-soft">{footer}</div>
            </main>

            {asideContent !== null ? (
                <aside className="hidden flex-col justify-center bg-subtle px-[88px] py-16 lg:flex">{asideContent}</aside>
            ) : null}
        </div>
    );
}

export default GuestLayout;
