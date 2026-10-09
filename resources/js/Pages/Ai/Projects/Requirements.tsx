import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { PersonPicker } from '@/Components/ai/projects/PersonPicker';
import { requirementState, type RequirementLevel } from '@/Components/ai/projects/projects';
import { Button } from '@/Components/ui/Button';
import { DataTable, type DataTableColumn } from '@/Components/ui/DataTable';
import { EmptyState } from '@/Components/ui/EmptyState';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { Select } from '@/Components/ui/Select';
import { TextArea } from '@/Components/ui/TextArea';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { ProjectLayout } from '@/Layouts/ProjectLayout';
import { formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { Requirement } from '@/types/ai';
import type { ProjectsRequirementsProps, RequirementScope, RequirementStorePayload } from '@/types/pages/ai-projects';
import type { Company, PageProps } from '@/types/shared';

const STATE: Record<RequirementLevel, { label: string; icon: IconName; tone: string }> = {
    overdue: { label: 'Vencido', icon: 'alert-triangle', tone: 'bg-status-failure-bg text-status-failure' },
    pending: { label: 'Pendiente', icon: 'hourglass', tone: 'bg-status-maintenance-bg text-status-maintenance' },
    answered: { label: 'Respondido', icon: 'check-circle', tone: 'bg-status-active-bg text-status-active' },
};

function StateChip({ level }: { level: RequirementLevel }) {
    const state = STATE[level];
    return (
        <span className={cx('inline-flex h-[26px] items-center gap-1.5 rounded-chip px-2.5 text-[13px] font-semibold whitespace-nowrap', state.tone)}>
            <Icon name={state.icon} size={13} />
            {state.label}
        </span>
    );
}

function RemindButton({ requirement }: { requirement: Requirement }) {
    const [sent, setSent] = useState(false);
    if (requirement.status === 'answered') return null;
    return (
        <button
            type="button"
            disabled={sent}
            onClick={() => router.post(route('ai.requirements.remind', { requirement: requirement.id }), {}, { preserveScroll: true, onSuccess: () => setSent(true) })}
            aria-label={sent ? `Recordatorio enviado: ${requirement.title}` : `Recordar a ${requirement.recipient?.name ?? requirement.recipient_label}: ${requirement.title}`}
            className="inline-flex h-11 items-center gap-1.5 rounded-control px-2.5 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg disabled:text-status-active"
        >
            <Icon name={sent ? 'check' : 'bell'} size={14} />
            {sent ? 'Recordado' : 'Recordar'}
        </button>
    );
}

const recipientLine = (requirement: Requirement, company: Company | undefined) =>
    [requirement.recipient?.name ?? requirement.recipient_label, requirement.recipient?.job_title ?? null, requirement.area_name, company?.short_name]
        .filter((part, index, all) => part && all.indexOf(part) === index)
        .join(' · ');

function blocksText(requirement: Requirement): string {
    if (requirement.blocks_task) {
        return requirement.status === 'answered' ? 'Ya no bloquea' : `${requirement.project_key} · ${requirement.blocks_task.key} ${requirement.blocks_task.title}`;
    }
    return '—';
}

function NewRequirementForm({ props, onDone }: { props: PageProps<ProjectsRequirementsProps>; onDone?: () => void }) {
    const { my_projects, recipients, reminder_every_days, project, companies } = props;
    const [projectKey, setProjectKey] = useState(project.key);
    const form = useForm<RequirementStorePayload>({ what: '', recipient_id: null, due_date: '', blocks_task: null });
    const tasks = my_projects.find((item) => item.key === projectKey)?.tasks ?? [];

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const errors: Partial<Record<keyof RequirementStorePayload, string>> = {};
        if (!form.data.what.trim()) errors.what = 'Escribe qué necesitas.';
        if (form.data.recipient_id === null) errors.recipient_id = 'Elige a quién se lo pides.';
        if (!form.data.due_date) errors.due_date = 'Pon una fecha límite.';
        else if (form.data.due_date < props.today) errors.due_date = 'La fecha límite no puede ser anterior a hoy.';
        if (Object.keys(errors).length > 0) {
            form.setError(errors);
            return;
        }
        form.post(route('ai.requirements.store', { project: projectKey }), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone?.();
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate aria-labelledby="new-requirement" className="flex flex-col gap-4 rounded-card border border-line bg-surface p-4 md:p-5">
            <h2 id="new-requirement" className="font-display text-lg font-bold">
                Nuevo requerimiento
            </h2>
            <TextArea
                label="Qué necesitas"
                rows={3}
                placeholder="Que dos asesores prueben el filtro de zonas y nos digan si falta alguna zona."
                value={form.data.what}
                error={form.errors.what}
                onChange={(event) => {
                    form.setData('what', event.target.value);
                    form.clearErrors('what');
                }}
            />
            <PersonPicker
                label="Para quién"
                options={recipients}
                value={form.data.recipient_id}
                onChange={(id) => {
                    form.setData('recipient_id', id);
                    form.clearErrors('recipient_id');
                }}
                companyName={(id) => companies.find((company) => company.id === id)?.short_name}
                error={form.errors.recipient_id}
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label="Fecha límite"
                    type="date"
                    min={props.today}
                    value={form.data.due_date}
                    error={form.errors.due_date}
                    onChange={(event) => {
                        form.setData('due_date', event.target.value);
                        form.clearErrors('due_date');
                    }}
                />
                <Select
                    label="Proyecto"
                    value={projectKey}
                    options={my_projects.map((item) => ({ value: item.key, label: item.key }))}
                    onChange={(event) => {
                        setProjectKey(event.target.value);
                        form.setData('blocks_task', null);
                    }}
                />
            </div>
            <Select
                label="Bloquea la tarea…"
                optional
                value={form.data.blocks_task ?? ''}
                placeholder="No bloquea ninguna"
                options={tasks.map((task) => ({ value: task.key, label: `${task.key} · ${task.title}` }))}
                onChange={(event) => form.setData('blocks_task', event.target.value || null)}
                hint="Mientras no responda, la tarea se marca como bloqueada."
                error={form.errors.blocks_task}
            />
            <p className="flex items-start gap-2 rounded-control bg-subtle px-3 py-2.5 text-[13px] leading-snug text-fg-soft">
                <Icon name="telegram" size={15} className="mt-0.5" />
                Se avisa por correo y Telegram. Si no responde, se le recuerda cada {reminder_every_days} días hasta la fecha límite.
            </p>
            <div className="flex flex-wrap gap-2">
                <Button type="submit" icon="message" loading={form.processing}>
                    Enviar requerimiento
                </Button>
                <Button
                    variant="secondary"
                    onClick={() => {
                        form.reset();
                        form.clearErrors();
                        onDone?.();
                    }}
                >
                    Cancelar
                </Button>
            </div>
        </form>
    );
}

/** B-Requirements: what IT is waiting for from users, with deadlines, reminders and the task each one blocks. */
export default function ProjectsRequirements(props: PageProps<ProjectsRequirementsProps>) {
    const { project, requirements, scope, my_projects, companies, today } = props;
    const [formOpen, setFormOpen] = useState(false);
    const companyOf = (id: number) => companies.find((company) => company.id === id);
    const states = requirements.map((requirement) => requirementState(requirement, today));
    const count = (level: RequirementLevel) => states.filter((state) => state.level === level).length;
    const allLabel = my_projects.length > 1 ? my_projects.map((item) => item.key).join(my_projects.length === 2 ? ' y ' : ', ') : 'Todos mis proyectos';
    const showProject = scope === 'all';

    const setScope = (value: RequirementScope) =>
        router.get(route('ai.projects.requirements', { project: project.key }), value === 'all' ? { scope: 'all' } : {}, { preserveState: true, preserveScroll: true });

    const columns: DataTableColumn<Requirement>[] = [
        {
            key: 'what',
            header: 'Qué se pide y a quién',
            render: (row) => (
                <span className="flex min-w-0 flex-col gap-0.5 py-3">
                    <b className="text-sm leading-snug">
                        {showProject ? <span className="tabular mr-1.5 text-xs text-fg-muted">{row.project_key}</span> : null}
                        {row.title}
                    </b>
                    <span className="text-[13px] leading-snug text-fg-muted">{recipientLine(row, companyOf(row.company_id))}</span>
                </span>
            ),
        },
        {
            key: 'due',
            header: 'Fecha límite',
            width: '96px',
            render: (row) => {
                const state = requirementState(row, today);
                return (
                    <span className="flex flex-col">
                        <span className="tabular text-sm font-semibold">{formatShortDate(row.due_date)}</span>
                        <span className={cx('text-xs', state.level === 'overdue' ? 'font-semibold text-status-failure' : 'text-fg-muted')}>{state.label}</span>
                    </span>
                );
            },
        },
        {
            key: 'state',
            header: 'Estado y recordatorios',
            width: '150px',
            render: (row) => (
                <span className="flex flex-col items-start gap-1 py-2">
                    <StateChip level={requirementState(row, today).level} />
                    <span className="tabular inline-flex items-center gap-1 text-xs text-fg-muted">
                        <Icon name="bell" size={12} />
                        {row.reminders_sent} {row.reminders_sent === 1 ? 'recordatorio' : 'recordatorios'}
                    </span>
                </span>
            ),
        },
        {
            key: 'blocks',
            header: 'Bloquea',
            width: 'minmax(0, 0.7fr)',
            render: (row) => (
                <span className={cx('text-[13px]', row.blocks_task && row.status !== 'answered' ? 'font-semibold text-status-failure' : 'text-fg-muted')}>{blocksText(row)}</span>
            ),
        },
        { key: 'action', header: 'Acción', hideHeader: true, width: '108px', align: 'end', render: (row) => <RemindButton requirement={row} /> },
    ];

    return (
        <ProjectLayout shared={props} title={`Requerimientos ${project.key} · Gestor IA`} project={project} tab="requirements">
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_360px] xl:items-start">
                <div className="flex min-w-0 flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="font-display text-xl font-bold md:text-2xl">Requerimientos a usuarios</h2>
                        <SegmentedControl<RequirementScope>
                            label="Qué requerimientos ver"
                            size="sm"
                            value={scope}
                            onChange={setScope}
                            options={[
                                { value: 'project', label: `Solo ${project.key}` },
                                { value: 'all', label: allLabel },
                            ]}
                        />
                    </div>

                    {requirements.length === 0 ? (
                        <EmptyState
                            icon="message"
                            title="No esperamos nada de nadie"
                            description="Cuando necesites datos, accesos o una validación de un usuario, pídeselo aquí: le llega por correo y Telegram, y se le recuerda solo."
                            actions={
                                <Button icon="plus" onClick={() => setFormOpen(true)} className="xl:hidden">
                                    Nuevo requerimiento
                                </Button>
                            }
                        />
                    ) : (
                        <>
                            <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-fg-soft">
                                {count('overdue') > 0 ? (
                                    <b className="inline-flex items-center gap-1.5 text-status-failure">
                                        <Icon name="alert-triangle" size={13} />
                                        {count('overdue')} {count('overdue') === 1 ? 'vencido' : 'vencidos'}
                                    </b>
                                ) : null}
                                <span>
                                    <b className="text-fg">{count('pending')}</b> {count('pending') === 1 ? 'pendiente' : 'pendientes'}
                                </span>
                                <span>
                                    <b className="text-fg">{count('answered')}</b> {count('answered') === 1 ? 'respondido' : 'respondidos'}
                                </span>
                                <span className="text-fg-muted md:ml-auto">Ordenado por fecha límite</span>
                            </p>

                            <div className="hidden overflow-hidden rounded-card border border-line bg-surface lg:block [&_[role=row]]:min-h-[64px]">
                                <DataTable label="Requerimientos" columns={columns} rows={requirements} rowKey={(row) => row.id} minWidth={680} density="compact" />
                            </div>

                            <ul className="flex flex-col gap-3 lg:hidden">
                                {requirements.map((row) => {
                                    const state = requirementState(row, today);
                                    return (
                                        <li
                                            key={row.id}
                                            className={cx('flex flex-col gap-2 rounded-card border bg-surface p-4', state.level === 'overdue' ? 'border-status-failure-line' : 'border-line')}
                                        >
                                            <div className="flex items-center gap-2">
                                                {showProject ? <span className="tabular text-xs font-bold text-fg-muted">{row.project_key}</span> : null}
                                                <StateChip level={state.level} />
                                                <span className={cx('ml-auto text-[13px]', state.level === 'overdue' ? 'font-semibold text-status-failure' : 'text-fg-muted')}>
                                                    {formatShortDate(row.due_date)} · {state.label}
                                                </span>
                                            </div>
                                            <b className="text-[15px] leading-snug">{row.title}</b>
                                            <span className="text-[13px] text-fg-muted">{recipientLine(row, companyOf(row.company_id))}</span>
                                            {row.blocks_task ? (
                                                <span className={cx('text-[13px]', row.status !== 'answered' ? 'font-semibold text-status-failure' : 'text-fg-muted')}>
                                                    {row.status !== 'answered' ? 'Bloquea ' : ''}
                                                    {blocksText(row)}
                                                </span>
                                            ) : null}
                                            <div className="flex items-center justify-between border-t border-line pt-1">
                                                <span className="tabular inline-flex items-center gap-1.5 text-[13px] text-fg-muted">
                                                    <Icon name="bell" size={13} />
                                                    {row.reminders_sent} {row.reminders_sent === 1 ? 'recordatorio' : 'recordatorios'}
                                                </span>
                                                <RemindButton requirement={row} />
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        </>
                    )}

                    {!formOpen ? (
                        <Button icon="plus" onClick={() => setFormOpen(true)} className="self-start xl:hidden">
                            Nuevo requerimiento
                        </Button>
                    ) : null}
                </div>

                <div className={cx('min-w-0 xl:sticky xl:top-[84px] xl:block', formOpen ? 'block' : 'hidden')}>
                    <NewRequirementForm props={props} onDone={() => setFormOpen(false)} />
                </div>
            </div>
        </ProjectLayout>
    );
}
