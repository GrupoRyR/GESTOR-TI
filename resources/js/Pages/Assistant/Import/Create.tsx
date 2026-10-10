import { router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { CurationHeading, CurationNoPermission } from '@/Components/assistant/curation/CurationParts';
import { ImportStepper } from '@/Components/assistant/KnowledgeBadges';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { Checkbox } from '@/Components/ui/Checkbox';
import { Chip } from '@/Components/ui/Chip';
import { FileDrop } from '@/Components/ui/FileDrop';
import { Icon } from '@/Components/ui/Icon';
import { IconButton } from '@/Components/ui/IconButton';
import { Select } from '@/Components/ui/Select';
import { AssistantLayout } from '@/Layouts/AssistantLayout';
import { can } from '@/lib/can';
import { formatFileSize, formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { ImportFile } from '@/types/assistant';
import type { ImportCreateProps, ImportUploadPayload } from '@/types/pages/assistant-curation';
import type { PageProps } from '@/types/shared';

const sheetLabel = (sheet: ImportFile['sheets'][number]) => `${sheet.name} · ${sheet.rows === null ? '[N]' : sheet.rows} filas`;

/** Read file: sheet select, header row checkbox and the detected columns. */
function FileCard({ file, onRemove, now }: { file: ImportFile; onRemove: () => void; now: Date }) {
    const form = useForm({ sheet: file.selected_sheet, header_row: file.header_row });
    const sheet = file.sheets.find((item) => item.name === form.data.sheet);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('assistant.import.update', { import: file.id }));
    };

    return (
        <form id="import-sheet" onSubmit={submit} className="flex flex-col gap-4 rounded-card border border-line bg-surface p-4">
            <div className="flex items-center gap-3">
                <span className="flex size-11 shrink-0 items-center justify-center rounded-icon bg-status-active-bg text-status-active">
                    <Icon name="table" size={22} />
                </span>
                <span className="flex min-w-0 flex-1 flex-col">
                    <b className="truncate text-[15px]">{file.name}</b>
                    <span className="text-[13px] text-fg-muted">
                        Subido {formatRelative(file.uploaded_at, now)} · {file.size_bytes === null ? '[PESO DEL ARCHIVO]' : formatFileSize(file.size_bytes)}
                    </span>
                </span>
                <Chip tone="green" icon="check" size="sm" className="max-sm:hidden">
                    Archivo leído
                </Chip>
                <IconButton icon="x" label="Quitar el archivo" onClick={onRemove} />
            </div>
            <div className="grid gap-x-4 gap-y-1 md:grid-cols-2 md:items-end">
                <Select
                    label="Hoja que vas a importar"
                    value={form.data.sheet}
                    onChange={(event) => form.setData('sheet', event.target.value)}
                    options={file.sheets.map((item) => ({ value: item.name, label: sheetLabel(item) }))}
                    error={form.errors.sheet}
                />
                <Checkbox
                    label="La fila 1 tiene los nombres de las columnas"
                    checked={form.data.header_row}
                    onChange={(event) => form.setData('header_row', event.target.checked)}
                />
            </div>
            <div className="flex flex-col gap-2">
                <span className="text-[13px] font-semibold text-fg-soft">
                    Columnas encontradas en la hoja {form.data.sheet}
                    {sheet && sheet.columns.length > 0 ? ` (${sheet.columns.length})` : ''}
                </span>
                {sheet && sheet.columns.length > 0 ? (
                    <ul aria-label={`Columnas de la hoja ${form.data.sheet}`} className="flex flex-wrap gap-1.5">
                        {sheet.columns.map((column) => (
                            <li key={column} className="inline-flex h-7 items-center rounded-chip border border-line bg-subtle px-2.5 text-[13px] font-semibold">
                                {column}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-[13px] text-fg-muted">Las columnas de esta hoja se leen cuando sigas al paso de columnas.</p>
                )}
            </div>
        </form>
    );
}

/** Excel import, step 1 (C-ImportUpload): upload the .xlsx, then pick the sheet. */
export default function ImportCreate(props: PageProps<ImportCreateProps>) {
    const { file, max_size_label, drafts_count, unanswered_count } = props;
    const now = new Date(props.now);
    const manage = can(props.auth.user, 'assistant.knowledge.manage');
    const upload = useForm<ImportUploadPayload>({ file: null });
    const uploadError = upload.errors.file ?? props.errors?.file;

    const layout = (children: ReactNode) => (
        <AssistantLayout
            shared={props}
            title="Importar desde Excel · Asistente TI"
            tab="import"
            counts={manage ? { drafts: drafts_count, unanswered: unanswered_count } : undefined}
        >
            {children}
        </AssistantLayout>
    );

    if (!manage) return layout(<CurationNoPermission what="Importar fichas" />);

    const onFile = (picked: File | null) => {
        upload.setData('file', picked);
        if (picked) upload.post(route('assistant.import.upload'), { forceFormData: true, preserveScroll: true });
    };
    const remove = () => {
        if (file) router.delete(route('assistant.import.destroy', { import: file.id }), { preserveScroll: true });
    };

    return layout(
        <div className="flex min-w-0 flex-col gap-5">
            <CurationHeading
                title="Importar fichas desde Excel"
                subtitle="Carga muchas fichas de una vez. Nada se guarda hasta que revises la vista previa."
                action={
                    <a href={route('assistant.import.template')} download className={buttonClasses({ variant: 'secondary', className: 'self-start md:self-auto' })}>
                        <Icon name="download" size={16} />
                        Descargar plantilla de importación
                    </a>
                }
            />
            <ImportStepper current={1} />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start xl:grid-cols-[minmax(0,1fr)_360px]">
                <section aria-label="Archivo de Excel" className="flex min-w-0 flex-col gap-4">
                    {file ? (
                        <FileCard file={file} onRemove={remove} now={now} />
                    ) : (
                        <FileDrop
                            label="Archivo de Excel"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            name="file"
                            onFile={onFile}
                            disabled={upload.processing}
                            error={uploadError}
                            hint={upload.processing ? 'Subiendo y leyendo el archivo…' : `Solo archivos .xlsx · máximo ${max_size_label}`}
                            className="[&>label]:min-h-[220px]"
                        />
                    )}
                    <div className="flex flex-wrap items-center justify-end gap-2 max-sm:flex-col-reverse max-sm:items-stretch">
                        {file ? (
                            <Button variant="secondary" onClick={remove}>
                                Cancelar
                            </Button>
                        ) : (
                            <Button variant="secondary" href={route('assistant.knowledge.index')}>
                                Cancelar
                            </Button>
                        )}
                        <Button type="submit" form="import-sheet" size="lg" disabled={!file} icon="chevron-right" iconPosition="end">
                            Seguir con las columnas
                        </Button>
                    </div>
                </section>

                <aside aria-label="Antes de importar" className="flex flex-col gap-3">
                    <div role="note" className="flex items-start gap-3 rounded-card border border-status-failure-line bg-status-failure-bg p-4 text-sm leading-snug">
                        <span className="mt-0.5 text-status-failure">
                            <Icon name="key" size={18} />
                        </span>
                        <span className="flex flex-col gap-1">
                            <b className="text-fg">Nunca pongas contraseñas en el Excel</b>
                            <span className="text-fg-soft">
                                Las fichas las lee el asistente. Las contraseñas van en Credenciales o en la Bóveda, nunca en una ficha.
                            </span>
                        </span>
                    </div>
                    <div className="flex items-start gap-3 rounded-card border border-line bg-surface p-4 text-sm leading-snug">
                        <span className="mt-0.5 text-fg-soft">
                            <Icon name="table" size={18} />
                        </span>
                        <span className="flex flex-col gap-1">
                            <b>Funciona mejor una hoja por tipo</b>
                            <span className="text-fg-soft">
                                Pon los informes en una hoja, los sistemas en otra y los procedimientos en otra. Así cada hoja se importa con su tipo de
                                ficha.
                            </span>
                        </span>
                    </div>
                    <div className="flex flex-col gap-1.5 rounded-card bg-subtle p-4 text-sm leading-snug">
                        <b>Qué trae la plantilla</b>
                        <span className="text-fg-soft">
                            Una hoja por cada tipo de ficha (Sistema, Informe, Flujo de datos, Procedimiento, Glosario) con sus columnas y una fila de
                            ejemplo.
                        </span>
                        <span className="text-fg-soft">Si una ficha ya existe con el mismo nombre, la vista previa te muestra qué cambia antes de importar.</span>
                    </div>
                </aside>
            </div>
        </div>,
    );
}
