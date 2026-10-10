import type { ReactNode } from 'react';
import { ImportStepper, type ImportStep } from '@/Components/assistant/KnowledgeBadges';
import { Chip } from '@/Components/ui/Chip';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { NoPermission } from '@/Components/ui/NoPermission';
import { cx } from '@/Components/ui/utils';
import type { DraftFieldOrigin, ImportFieldChange, ImportMatchKind, ImportRowResult } from '@/types/assistant';

/** Every curation page needs `assistant.knowledge.manage`. */
export function CurationNoPermission({ what }: { what: string }) {
    return (
        <NoPermission
            title={`${what} necesita el permiso de la base de conocimiento`}
            description="Hoy lo tienen Jhon Castro y Leonardo Herrera. Si necesitas administrar las fichas del asistente, pídeselo a ellos."
        />
    );
}

export interface CurationHeadingProps {
    title: string;
    subtitle: ReactNode;
    /** Import step (renders the stepper next to the title). */
    step?: ImportStep;
    /** Button or link on the right (step 1 template download). */
    action?: ReactNode;
}

/** Page title + subtitle; on import pages the stepper sits on the right (below on phone). */
export function CurationHeading({ title, subtitle, step, action }: CurationHeadingProps) {
    return (
        <div className="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-end md:justify-between md:gap-x-6">
            <div className="flex min-w-0 flex-col gap-1">
                <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[28px]">{title}</h1>
                <p className="text-sm text-fg-soft">{subtitle}</p>
            </div>
            {action ?? (step ? <ImportStepper current={step} /> : null)}
        </div>
    );
}

// ---- column mapping ---------------------------------------------------------

const MATCH: Record<ImportMatchKind, { icon: IconName; label: string; tone: string }> = {
    auto: { icon: 'check', label: 'Automática', tone: 'text-status-active' },
    manual: { icon: 'edit', label: 'Elegida a mano', tone: 'text-status-scheduled' },
    fixed: { icon: 'lock', label: 'Fijo', tone: 'text-fg-soft' },
    skip: { icon: 'minus-circle', label: 'No se importa', tone: 'text-fg-muted' },
};

/** How a column got its field, icon + text ("Fijo: Informe"). */
export function MatchKindLabel({ kind, typeLabel, className }: { kind: ImportMatchKind; typeLabel: string; className?: string }) {
    const info = MATCH[kind];
    return (
        <span className={cx('inline-flex items-center gap-1.5 text-[13px] font-semibold whitespace-nowrap', info.tone, className)}>
            <Icon name={info.icon} size={14} strokeWidth={2.4} />
            {kind === 'fixed' ? `${info.label}: ${typeLabel}` : info.label}
        </span>
    );
}

// ---- preview rows -----------------------------------------------------------

/** "Nueva", "Actualiza K-031" or "Con error · no se importa". */
export function OutcomeChip({ row }: { row: ImportRowResult }) {
    if (row.outcome === 'error') {
        return (
            <Chip tone="red" icon="x-circle" size="sm">
                Con error · no se importa
            </Chip>
        );
    }
    if (row.outcome === 'update') {
        return (
            <Chip tone="blue" icon="refresh" size="sm">
                {row.card ? `Actualiza ${row.card.key}` : 'Actualiza una ficha'}
            </Chip>
        );
    }
    return (
        <Chip tone="green" icon="plus" size="sm">
            Nueva
        </Chip>
    );
}

/** Old → new per field; the old value is struck through and announced as "antes". */
export function ChangeList({ changes, className }: { changes: ImportFieldChange[]; className?: string }) {
    return (
        <dl className={cx('flex flex-col gap-1.5 text-[13px]', className)}>
            {changes.map((change) => (
                <div key={change.label} className="grid grid-cols-[minmax(0,120px)_minmax(0,1fr)] gap-x-3 max-sm:grid-cols-1">
                    <dt className="text-fg-muted">{change.label}</dt>
                    <dd className="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5">
                        <span className="sr-only">antes: </span>
                        <s className="text-fg-muted">{change.from ?? 'vacío'}</s>
                        <Icon name="chevron-right" size={14} className="text-fg-muted" />
                        <span className="sr-only">ahora: </span>
                        <b className="font-semibold text-fg">{change.to}</b>
                    </dd>
                </div>
            ))}
        </dl>
    );
}

// ---- drafts -----------------------------------------------------------------

const ORIGIN: Record<DraftFieldOrigin, string> = { message: 'Del mensaje', assumed: 'Supuesto', missing: 'Falta' };

/** Where a proposed field came from. "Falta" is amber with an icon; the others are neutral. */
export function FieldOriginTag({ origin }: { origin: DraftFieldOrigin }) {
    const missing = origin === 'missing';
    return (
        <span
            className={cx(
                'inline-flex h-[18px] shrink-0 items-center gap-1 rounded-[8px] px-1.5 text-[11px] font-semibold whitespace-nowrap',
                missing ? 'bg-status-maintenance-bg text-status-maintenance' : 'bg-subtle text-fg-soft',
            )}
        >
            {missing ? <Icon name="alert-triangle" size={11} strokeWidth={2.4} /> : null}
            {ORIGIN[origin]}
        </span>
    );
}
