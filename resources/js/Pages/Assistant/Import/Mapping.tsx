import { useForm } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';
import { KnowledgeTypeIcon } from '@/Components/assistant/KnowledgeBadges';
import { CurationHeading, CurationNoPermission, MatchKindLabel } from '@/Components/assistant/curation/CurationParts';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { AssistantLayout } from '@/Layouts/AssistantLayout';
import { can } from '@/lib/can';
import { mappingSummary, missingRequired, setColumnField } from '@/lib/importMapping';
import { joinEs } from '@/lib/curation';
import { knowledgeTypeLabel } from '@/lib/knowledge';
import { route } from '@/lib/route';
import type { ImportColumn, ImportField } from '@/types/assistant';
import type { ImportMappingPayload, ImportMappingProps } from '@/types/pages/assistant-curation';
import type { PageProps } from '@/types/shared';

const SKIP = '';

function FieldSelect({ column, fields, onChange }: { column: ImportColumn; fields: ImportField[]; onChange: (field: string | null) => void }) {
    const fixed = column.match === 'fixed';
    return (
        <span className="relative block">
            <select
                aria-label={`Campo de la ficha para la columna ${column.name}`}
                value={column.field ?? SKIP}
                disabled={fixed}
                onChange={(event) => onChange(event.target.value === SKIP ? null : event.target.value)}
                className={cx(
                    'h-11 w-full appearance-none rounded-control border bg-surface pr-9 pl-3 text-[15px] text-fg',
                    'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-fg disabled:cursor-not-allowed disabled:bg-subtle',
                    column.match === 'manual' ? 'border-status-scheduled' : 'border-line-strong hover:border-fg-muted',
                )}
            >
                {fields
                    .filter((field) => fixed || field.key !== 'type')
                    .map((field) => (
                        <option key={field.key} value={field.key}>
                            {field.label}
                            {field.required ? ' (obligatorio)' : ''}
                        </option>
                    ))}
                <option value={SKIP}>No importar</option>
            </select>
            <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-fg-muted">
                <Icon name="chevron-down" size={16} />
            </span>
        </span>
    );
}

/** Excel import, step 2 (C-ImportMapping): which card field each Excel column feeds. */
export default function ImportMapping(props: PageProps<ImportMappingProps>) {
    const { file, mapping, fields, review_months, drafts_count, unanswered_count } = props;
    const manage = can(props.auth.user, 'assistant.knowledge.manage');
    const form = useForm<{ columns: ImportColumn[] }>({ columns: mapping.columns });
    const messageId = useId();

    const columns = form.data.columns;
    const summary = mappingSummary(columns);
    const missing = missingRequired(columns, mapping.type);
    const typeLabel = knowledgeTypeLabel(mapping.type);
    const sheet = file.sheets.find((item) => item.name === mapping.sheet);
    const columnsError = form.errors.columns ?? props.errors?.columns;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (missing.length > 0) return;
        form.transform(({ columns: current }): ImportMappingPayload => ({ columns: current.map(({ name, field }) => ({ name, field })) }));
        form.put(route('assistant.import.mapping.update', { import: file.id }));
    };

    const content = !manage ? (
        <CurationNoPermission what="Importar fichas" />
    ) : (
        <div className="flex min-w-0 flex-col gap-5">
            <CurationHeading
                title="¿Qué es cada columna?"
                subtitle={`${file.name} · hoja ${mapping.sheet}${sheet?.rows ? ` · ${sheet.rows} filas` : ''}. Revisa las que no se asociaron solas.`}
                step={2}
            />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px] lg:items-start">
                <form onSubmit={submit} aria-label="Columnas del Excel" className="flex min-w-0 flex-col gap-4">
                    <div className="overflow-hidden rounded-card border border-line bg-surface">
                        <div
                            aria-hidden="true"
                            className="grid grid-cols-[150px_minmax(0,1fr)_220px_160px] gap-x-3.5 bg-subtle px-4 py-2.5 text-[13px] font-semibold text-fg-soft max-xl:hidden"
                        >
                            <span>Columna del Excel</span>
                            <span>Ejemplo (fila 2)</span>
                            <span>Campo de la ficha</span>
                            <span>Cómo se asoció</span>
                        </div>
                        <ul className="flex flex-col">
                            {columns.map((column) => {
                                const muted = column.match === 'skip';
                                return (
                                    <li
                                        key={column.name}
                                        className={cx(
                                            'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 border-t border-line px-4 py-3 first:border-t-0 xl:first:border-t',
                                            'xl:grid-cols-[150px_minmax(0,1fr)_220px_160px] xl:gap-x-3.5 xl:py-1.5',
                                            muted && 'bg-subtle/60 text-fg-muted',
                                        )}
                                    >
                                        <b className={cx('min-w-0 truncate text-[15px]', muted && 'font-semibold')}>{column.name}</b>
                                        <span className="col-span-2 min-w-0 truncate text-sm text-fg-soft max-xl:order-3 xl:col-span-1">
                                            <span className="xl:sr-only">Ejemplo: </span>
                                            {column.sample || '—'}
                                        </span>
                                        <span className="col-span-2 max-xl:order-4 xl:col-span-1">
                                            <FieldSelect
                                                column={column}
                                                fields={fields}
                                                onChange={(field) => form.setData('columns', setColumnField(columns, column.name, field))}
                                            />
                                        </span>
                                        <MatchKindLabel kind={column.match} typeLabel={typeLabel} className="max-xl:order-2 max-xl:justify-self-end" />
                                    </li>
                                );
                            })}
                        </ul>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 max-sm:flex-col max-sm:items-stretch">
                        <Button variant="secondary" href={route('assistant.import.create')} className="max-sm:order-3">
                            Atrás
                        </Button>
                        <p
                            id={messageId}
                            role="status"
                            className={cx(
                                'flex items-start gap-2 text-sm max-sm:order-1 sm:ml-auto',
                                missing.length > 0 || columnsError ? 'font-semibold text-status-failure' : 'text-fg-soft',
                            )}
                        >
                            <span className="mt-0.5">
                                <Icon name={missing.length > 0 || columnsError ? 'alert-triangle' : 'check-circle'} size={16} />
                            </span>
                            {missing.length > 0
                                ? `Falta elegir la columna de ${joinEs(missing.map((field) => field.label))}. Sin ${missing.length === 1 ? 'ese campo' : 'esos campos'} no hay vista previa.`
                                : (columnsError ?? 'Los campos obligatorios ya tienen columna.')}
                        </p>
                        <Button
                            type="submit"
                            size="lg"
                            disabled={missing.length > 0}
                            aria-describedby={messageId}
                            loading={form.processing}
                            icon="chevron-right"
                            iconPosition="end"
                            className="max-sm:order-2"
                        >
                            Ver la vista previa
                        </Button>
                    </div>
                </form>

                <aside aria-label="Cómo se leen las columnas" className="flex flex-col gap-3 text-sm leading-snug">
                    <div className="flex flex-col gap-2.5 rounded-card border border-line bg-surface p-4">
                        <span className="text-[13px] font-semibold text-fg-soft">Tipo de ficha para esta hoja</span>
                        <div className="flex items-center gap-2.5">
                            <KnowledgeTypeIcon type={mapping.type} size="md" />
                            <b className="text-base">{typeLabel}</b>
                            <span className="ml-auto inline-flex h-6 items-center gap-1 rounded-chip border border-line-strong px-2 text-xs font-bold">
                                <Icon name="lock" size={12} />
                                Fijo
                            </span>
                        </div>
                        <span className="text-fg-soft">
                            Todas las filas de la hoja {mapping.sheet} entran como {typeLabel}. La columna Tipo solo se revisa.
                        </span>
                    </div>
                    <div className="flex flex-col gap-2 rounded-card border border-line bg-surface p-4">
                        <b>
                            {summary.auto} de {summary.total} columnas se asociaron solas
                        </b>
                        <MatchKindLabel kind="auto" typeLabel={typeLabel} className="font-normal" />
                        <MatchKindLabel kind="manual" typeLabel={typeLabel} className="font-normal" />
                        <MatchKindLabel kind="skip" typeLabel={typeLabel} className="font-normal" />
                    </div>
                    <div className="flex items-start gap-3 rounded-card bg-subtle p-4">
                        <span className="mt-0.5 text-fg-soft">
                            <Icon name="user" size={18} />
                        </span>
                        <span className="flex flex-col gap-1">
                            <b>Dueño se busca por nombre</b>
                            <span className="text-fg-soft">
                                Cada nombre se cruza con los usuarios del Gestor. Si hay dos personas con el mismo nombre, la vista previa te pide elegir.
                            </span>
                        </span>
                    </div>
                    <p className="px-1 text-fg-soft">
                        <b className="text-fg">Fecha de revisión</b> no viene en el Excel. Cada ficha queda para revisar en {review_months} meses; la cambias
                        después.
                    </p>
                </aside>
            </div>
        </div>
    );

    return (
        <AssistantLayout
            shared={props}
            title="Columnas · Importar desde Excel"
            tab="import"
            counts={manage ? { drafts: drafts_count, unanswered: unanswered_count } : undefined}
        >
            {content}
        </AssistantLayout>
    );
}
