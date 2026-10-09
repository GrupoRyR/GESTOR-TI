import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { BudgetBars, CostsLock } from '@/Components/ai/projects/ProjectParts';
import { addDays, burnRate, costsTotal, hoursByPerson, projectedEnd, weekLabel } from '@/Components/ai/projects/projects';
import { TimeEntryFields, useTimeEntryForm } from '@/Components/ai/projects/TimeEntryFields';
import { Elapsed } from '@/Components/ai/TimerButton';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { DataTable, type DataTableColumn } from '@/Components/ui/DataTable';
import { Dialog } from '@/Components/ui/Dialog';
import { FileDrop } from '@/Components/ui/FileDrop';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { IconButton } from '@/Components/ui/IconButton';
import { LineChart } from '@/Components/ui/LineChart';
import { NoPermission } from '@/Components/ui/NoPermission';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { Select } from '@/Components/ui/Select';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { ProjectLayout } from '@/Layouts/ProjectLayout';
import { can } from '@/lib/can';
import { formatCOP, formatLongDate, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import { formatDecimalHours } from '@/lib/timer';
import type { CostType, ProjectCost, TimeEntry, TimeEntryOrigin } from '@/types/ai';
import type { CostPayload, ProjectsCostsProps } from '@/types/pages/ai-projects';
import type { PageProps } from '@/types/shared';

const DAY = 86_400_000;
const dayIndex = (iso: string) => Math.round(Date.parse(`${iso.slice(0, 10)}T00:00:00Z`) / DAY);
const fromDayIndex = (day: number) => new Date(day * DAY).toISOString().slice(0, 10);

/** "mié 7 oct". */
const shortDay = (iso: string) => `${formatLongDate(iso).slice(0, 3)} ${formatShortDate(iso)}`;

const ORIGIN: Record<TimeEntryOrigin, { label: string; icon: IconName }> = {
    timer: { label: 'Cronómetro', icon: 'clock' },
    manual: { label: 'Manual', icon: 'edit' },
    telegram: { label: 'Telegram', icon: 'telegram' },
};

const COST_TYPE: Record<CostType, string> = {
    team: 'Horas del equipo',
    license: 'Licencia',
    hosting: 'Hosting',
    ai_usage: 'Consumo de IA',
    external_provider: 'Proveedor externo',
    other: 'Otro',
};

const RECURRENCE: Record<ProjectCost['recurrence'], string> = { once: 'Único', monthly: 'Mensual', computed: 'Calculado' };

function OriginChip({ origin }: { origin: TimeEntryOrigin }) {
    return (
        <span className="inline-flex h-[26px] items-center gap-1.5 rounded-chip bg-subtle px-2.5 text-[13px] font-semibold whitespace-nowrap text-fg-soft">
            <Icon name={ORIGIN[origin].icon} size={13} />
            {ORIGIN[origin].label}
        </span>
    );
}

function AddHoursDialog({ open, onClose, props }: { open: boolean; onClose: () => void; props: PageProps<ProjectsCostsProps> }) {
    const { form, submit } = useTimeEntryForm(props.today, { project_key: props.project.key, task_key: null });
    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Agregar horas"
            description="Para lo que hiciste sin cronómetro: reuniones, llamadas o trabajo fuera del Gestor."
            onSubmit={(event: FormEvent<HTMLFormElement>) => {
                event.preventDefault();
                submit(onClose);
            }}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Guardar
                    </Button>
                </>
            }
        >
            <TimeEntryFields form={form} today={props.today} tasks={props.tasks} />
        </Dialog>
    );
}

const EDITABLE_TYPES: CostType[] = ['license', 'hosting', 'external_provider', 'other'];

function AddCostDialog({ open, onClose, projectKey, today }: { open: boolean; onClose: () => void; projectKey: string; today: string }) {
    const form = useForm<CostPayload>({ type: '', concept: '', recurrence: 'once', amount: null, date: today, attachment: null });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const errors: Partial<Record<keyof CostPayload, string>> = {};
        if (!form.data.type) errors.type = 'Elige el tipo.';
        if (!form.data.concept.trim()) errors.concept = 'Escribe el concepto.';
        if (form.data.amount === null || form.data.amount <= 0) errors.amount = 'Pon el valor en pesos.';
        if (!form.data.date) errors.date = 'Pon la fecha.';
        if (Object.keys(errors).length > 0) {
            form.setError(errors);
            return;
        }
        form.post(route('ai.costs.store', { project: projectKey }), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Agregar costo"
            description="Licencias, hosting, proveedores externos u otros gastos. Las horas del equipo y el consumo de IA se suman solos."
            onSubmit={submit}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Agregar costo
                    </Button>
                </>
            }
        >
            <Select
                label="Tipo"
                value={form.data.type}
                placeholder="Elige el tipo"
                options={EDITABLE_TYPES.map((type) => ({ value: type, label: COST_TYPE[type] }))}
                error={form.errors.type}
                onChange={(event) => {
                    form.setData('type', event.target.value as CostType);
                    form.clearErrors('type');
                }}
            />
            <TextField
                label="Concepto"
                value={form.data.concept}
                error={form.errors.concept}
                placeholder="Consultor SAP B1: vista de cartera"
                onChange={(event) => {
                    form.setData('concept', event.target.value);
                    form.clearErrors('concept');
                }}
            />
            <div className="flex flex-col gap-1.5">
                <span className="text-[13px] font-semibold">Frecuencia</span>
                <SegmentedControl<CostPayload['recurrence']>
                    label="Frecuencia"
                    value={form.data.recurrence}
                    onChange={(value) => form.setData('recurrence', value)}
                    options={[
                        { value: 'once', label: 'Único' },
                        { value: 'monthly', label: 'Mensual' },
                    ]}
                />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label="Valor (pesos)"
                    inputMode="numeric"
                    value={form.data.amount === null ? '' : form.data.amount.toLocaleString('es-CO')}
                    error={form.errors.amount}
                    hint={form.data.amount ? formatCOP(form.data.amount) : undefined}
                    onChange={(event) => {
                        const digits = event.target.value.replace(/\D/g, '');
                        form.setData('amount', digits ? Number(digits) : null);
                        form.clearErrors('amount');
                    }}
                />
                <TextField
                    label="Fecha"
                    type="date"
                    max={today}
                    value={form.data.date}
                    error={form.errors.date}
                    onChange={(event) => form.setData('date', event.target.value)}
                />
            </div>
            <FileDrop
                label="Soporte"
                hint="Factura o recibo en PDF o imagen (opcional)"
                accept="application/pdf,image/*"
                onFile={(file) => form.setData('attachment', file)}
                error={form.errors.attachment}
            />
        </Dialog>
    );
}

/** B-ProjectCosts: estimate vs actual, hours chart with projection, the weekly hours log and the project costs. */
export default function ProjectsCosts(props: PageProps<ProjectsCostsProps>) {
    const { project, budget, curve, remaining_hours, recent_hours, recent_weeks, pace_note, week, entries, total_by_person, running_timers, costs, telegram_example, today, auth } = props;
    const [hoursOpen, setHoursOpen] = useState(false);
    const [costOpen, setCostOpen] = useState(false);
    const title = `Tiempo y costos ${project.key} · Gestor IA`;

    if (!can(auth.user, 'ai.costs.view')) {
        return (
            <ProjectLayout shared={props} title={title} project={project} tab="costs">
                <NoPermission
                    title="Tiempo y costos es solo para quien tiene permiso de costos"
                    description="Las horas de cada tarea siguen en el tablero. Si necesitas ver tarifas y costos, pídele el permiso a Jhon Castro."
                    homeHref={route('ai.projects.show', { project: project.key })}
                />
            </ProjectLayout>
        );
    }

    const pace = burnRate(recent_hours, recent_weeks);
    const end = projectedEnd(today, remaining_hours, pace);
    const projectedTotal = budget.hours.actual + remaining_hours;
    const weekTotals = hoursByPerson(entries, week);
    const total = costsTotal(costs);
    const approvedCost = budget.cost?.estimate ?? 0;
    const percent = approvedCost > 0 ? Math.round((total / approvedCost) * 100) : null;
    const navigateWeek = (days: number) =>
        router.get(route('ai.projects.costs', { project: project.key }), { week: addDays(week.from, days) }, { preserveState: true, preserveScroll: true });
    const isCurrentWeek = today >= week.from && today <= week.to;

    const toPoints = (points: { date: string; value: number }[]) => points.map((point) => ({ x: dayIndex(point.date), y: point.value }));
    const xTicks = [curve.plan[0]?.date, today, budget.due_date, end ?? budget.projected_due_date]
        .filter((date): date is string => Boolean(date))
        .filter((date, index, all) => all.indexOf(date) === index)
        .sort()
        .map((date) => ({ x: dayIndex(date), label: date === today ? 'Hoy' : formatShortDate(date) }));

    const entryColumns: DataTableColumn<TimeEntry>[] = [
        { key: 'date', header: 'Fecha', width: '92px', render: (row) => <span className="tabular text-[13px]">{shortDay(row.date)}</span> },
        {
            key: 'person',
            header: 'Persona',
            width: '170px',
            render: (row) => (
                <span className="flex min-w-0 items-center gap-2">
                    <Avatar name={row.person.name} size="xs" />
                    <span className="truncate text-[13px] font-semibold">{row.person.name}</span>
                </span>
            ),
        },
        {
            key: 'task',
            header: 'Tarea',
            render: (row) =>
                row.task ? (
                    <span className="truncate text-[13px]">
                        <b>{row.task.key}</b> {row.task.title}
                    </span>
                ) : (
                    <span className="text-[13px] text-fg-muted">Sin tarea · proyecto</span>
                ),
        },
        { key: 'hours', header: 'Horas', width: '60px', align: 'end', render: (row) => <b className="tabular text-[13px]">{formatDecimalHours(row.hours)}</b> },
        { key: 'origin', header: 'Origen', width: '128px', render: (row) => <OriginChip origin={row.origin} /> },
        { key: 'note', header: 'Qué hizo', render: (row) => <span className="line-clamp-2 py-2 text-[13px] text-fg-soft">{row.note}</span> },
    ];

    const costColumns: DataTableColumn<ProjectCost>[] = [
        { key: 'type', header: 'Tipo', width: '150px', render: (row) => <span className="text-[13px] font-semibold">{COST_TYPE[row.type]}</span> },
        {
            key: 'concept',
            header: 'Concepto',
            render: (row) => (
                <span className="flex min-w-0 flex-col gap-0.5 py-3">
                    <span className="text-[13px] font-semibold">{row.concept}</span>
                    {row.detail ? <span className="text-xs leading-snug text-fg-muted">{row.detail}</span> : null}
                </span>
            ),
        },
        { key: 'recurrence', header: 'Frecuencia', width: '104px', render: (row) => <span className="text-[13px]">{RECURRENCE[row.recurrence]}</span> },
        { key: 'amount', header: 'Valor', width: '132px', align: 'end', render: (row) => <b className="tabular text-[13px]">{formatCOP(row.amount)}</b> },
        {
            key: 'date',
            header: 'Fecha',
            width: '92px',
            render: (row) => (
                <span className="tabular text-[13px] text-fg-soft">
                    {row.type === 'team' ? `al ${formatShortDate(row.date)}` : row.type === 'ai_usage' ? formatShortDate(row.date).split(' ')[1] : formatShortDate(row.date)}
                </span>
            ),
        },
        {
            key: 'attachment',
            header: 'Soporte',
            width: '190px',
            render: (row) =>
                row.attachment ? (
                    <a href={row.attachment.url} className="inline-flex min-h-11 min-w-0 items-center gap-1.5 text-[13px] font-semibold text-fg underline-offset-2 hover:underline">
                        <Icon name="paperclip" size={13} />
                        <span className="truncate">{row.attachment.name}</span>
                    </a>
                ) : row.type === 'team' ? (
                    <a href={route('admin.costs.index')} className="inline-flex min-h-11 items-center gap-1.5 text-[13px] font-semibold text-fg-soft underline-offset-2 hover:underline">
                        <Icon name="lock" size={13} />
                        Tarifas en Administración
                    </a>
                ) : (
                    <span className="inline-flex items-center gap-1.5 text-[13px] text-fg-muted">
                        <Icon name="refresh" size={13} />
                        Automático
                    </span>
                ),
        },
    ];

    return (
        <ProjectLayout shared={props} title={title} project={project} tab="costs">
            <div className="flex flex-col gap-4">
                <div className="grid grid-cols-1 gap-4 xl:grid-cols-[340px_minmax(0,1fr)]">
                    <section aria-labelledby="budget" className="flex flex-col gap-4 rounded-card border border-line bg-surface p-4 md:p-5">
                        <div className="flex flex-col gap-1">
                            <h2 id="budget" className="text-base font-bold">
                                Estimado contra real
                            </h2>
                            <CostsLock />
                        </div>
                        <BudgetBars budget={budget} showCost />
                    </section>

                    <section aria-labelledby="curve" className="flex min-w-0 flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
                        <h2 id="curve" className="text-base font-bold">
                            Horas acumuladas y proyección
                        </h2>
                        <LineChart
                            title={`Horas acumuladas de ${project.key}: plan aprobado, real y proyección`}
                            summary={`Van ${budget.hours.actual} h de ${budget.hours.estimate} h aprobadas; al ritmo actual se termina el ${end ? formatShortDate(end) : 'sin fecha'} con ${projectedTotal} h.`}
                            series={[
                                { key: 'actual', label: 'Real', color: 1, area: true, points: toPoints(curve.actual) },
                                { key: 'plan', label: 'Plan aprobado', color: 'muted', points: toPoints(curve.plan) },
                                { key: 'projection', label: 'Proyección al ritmo actual', color: 1, dashed: true, points: toPoints(curve.projection) },
                            ]}
                            references={[
                                { y: budget.hours.estimate * 0.8, label: `80 % · ${budget.hours.estimate * 0.8} h` },
                                { y: budget.hours.estimate, label: `100 % · ${budget.hours.estimate} h` },
                            ]}
                            xTicks={xTicks}
                            formatX={(x) => formatShortDate(fromDayIndex(x))}
                            formatY={(y) => `${Math.round(y)} h`}
                            height={220}
                        />
                        <p className="text-[13px] leading-snug text-fg-soft">
                            Con las tareas abiertas faltan unas {remaining_hours} h. Al ritmo de las últimas {recent_weeks === 1 ? 'semana' : `${recent_weeks} semanas`}, unas{' '}
                            {Math.round(pace)} h por semana{pace_note ? ` ${pace_note}` : ''},{' '}
                            {end ? (
                                <>
                                    se termina el <b>{formatShortDate(end)}</b> con <b>{projectedTotal} h</b>.
                                </>
                            ) : (
                                'no hay ritmo suficiente para proyectar la fecha.'
                            )}
                        </p>
                    </section>
                </div>

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_300px] xl:items-start">
                    <section aria-labelledby="hours-log" className="flex min-w-0 flex-col overflow-hidden rounded-card border border-line bg-surface">
                        <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3 md:px-5">
                            <h2 id="hours-log" className="mr-auto text-base font-bold">
                                Registro de horas
                            </h2>
                            <div className="flex items-center">
                                <IconButton icon="chevron-left" label="Semana anterior" onClick={() => navigateWeek(-7)} />
                                <span className="tabular px-1 text-[13px] font-semibold whitespace-nowrap">{weekLabel(week)}</span>
                                <IconButton icon="chevron-right" label="Semana siguiente" onClick={() => navigateWeek(7)} disabled={isCurrentWeek} />
                            </div>
                            <Button variant="secondary" icon="download" href={route('ai.projects.costs.export', { project: project.key, week: week.from })}>
                                Exportar
                            </Button>
                            <Button icon="plus" onClick={() => setHoursOpen(true)}>
                                Agregar horas
                            </Button>
                        </div>
                        {entries.length === 0 ? (
                            <p className="px-5 py-8 text-sm text-fg-muted">Nadie cargó horas en esta semana.</p>
                        ) : (
                            <>
                                <div className="hidden lg:block">
                                    <DataTable label={`Horas de la ${weekLabel(week).toLowerCase()}`} columns={entryColumns} rows={entries} rowKey={(row) => row.id} minWidth={860} density="compact" />
                                </div>
                                <ul className="divide-y divide-line lg:hidden">
                                    {entries.map((row) => (
                                        <li key={row.id} className="flex flex-col gap-1.5 px-4 py-3">
                                            <div className="flex items-center gap-2 text-[13px]">
                                                <span className="tabular text-fg-muted">{shortDay(row.date)}</span>
                                                <span className="font-semibold">{row.person.name}</span>
                                                <b className="tabular ml-auto">{formatDecimalHours(row.hours)}</b>
                                            </div>
                                            <span className="text-[13px]">{row.task ? `${row.task.key} ${row.task.title}` : 'Sin tarea · proyecto'}</span>
                                            <span className="text-[13px] text-fg-soft">{row.note}</span>
                                            <OriginChip origin={row.origin} />
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                        {running_timers.map((timer) => (
                            <p key={timer.person.id} className="flex flex-wrap items-center gap-1.5 border-t border-line bg-subtle px-4 py-2.5 text-[13px] text-fg-soft md:px-5">
                                <Icon name="clock" size={14} />
                                Además corre el cronómetro de {timer.person.name.split(' ')[0]} en {timer.task_key}:{' '}
                                <Elapsed startedAt={timer.started_at} clockStartAt={props.now} className="text-fg" />. Se suma al pararlo.
                            </p>
                        ))}
                    </section>

                    <aside className="flex flex-col gap-4">
                        <section aria-labelledby="by-person" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
                            <h2 id="by-person" className="text-base font-bold">
                                Total del proyecto por persona
                            </h2>
                            <dl className="flex flex-col gap-2">
                                {total_by_person.map((row) => (
                                    <div key={row.person.id} className="flex items-center gap-2.5">
                                        <Avatar name={row.person.name} size="sm" />
                                        <dt className="flex min-w-0 flex-1 flex-col">
                                            <span className="truncate text-sm font-semibold">{row.person.name}</span>
                                            <span className="tabular text-xs text-fg-muted">
                                                Esta semana {formatDecimalHours(weekTotals.find((item) => item.person.id === row.person.id)?.hours ?? 0)}
                                            </span>
                                        </dt>
                                        <dd className="tabular text-base font-bold">{formatDecimalHours(row.hours)}</dd>
                                    </div>
                                ))}
                                <div className="flex items-center justify-between border-t border-line pt-2">
                                    <dt className="text-sm font-bold">Total</dt>
                                    <dd className="tabular text-base font-bold">{formatDecimalHours(total_by_person.reduce((sum, row) => sum + row.hours, 0))}</dd>
                                </div>
                            </dl>
                        </section>
                        <p className="flex items-start gap-2 rounded-card bg-subtle p-4 text-[13px] leading-snug text-fg-soft">
                            <Icon name="telegram" size={16} className="mt-0.5 shrink-0" />
                            <span>
                                También puedes cargar horas con <code className="rounded-[6px] bg-surface px-1.5 py-0.5 font-mono text-[12px] text-fg">{telegram_example}</code>
                            </span>
                        </p>
                        <p className="flex items-start gap-2 rounded-card bg-subtle p-4 text-[13px] leading-snug text-fg-soft">
                            <Icon name="bell" size={16} className="mt-0.5 shrink-0" />
                            Los viernes a las 4:00 p. m. el Gestor le recuerda a cada persona los días de la semana sin horas cargadas.
                        </p>
                    </aside>
                </div>

                <section aria-labelledby="costs" className="flex min-w-0 flex-col overflow-hidden rounded-card border border-line bg-surface">
                    <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3 md:px-5">
                        <div className="mr-auto flex flex-col gap-0.5">
                            <h2 id="costs" className="text-base font-bold">
                                Costos del proyecto
                            </h2>
                            <CostsLock />
                        </div>
                        <Button icon="plus" variant="secondary" onClick={() => setCostOpen(true)}>
                            Agregar costo
                        </Button>
                    </div>
                    <div className="hidden lg:block">
                        <DataTable label="Costos del proyecto" columns={costColumns} rows={costs} rowKey={(row) => row.id} minWidth={900} density="compact" />
                    </div>
                    <ul className="divide-y divide-line lg:hidden">
                        {costs.map((row) => (
                            <li key={row.id} className="flex flex-col gap-1 px-4 py-3">
                                <div className="flex items-center gap-2 text-[13px]">
                                    <span className="font-semibold">{COST_TYPE[row.type]}</span>
                                    <span className="text-fg-muted">· {RECURRENCE[row.recurrence]}</span>
                                    <b className="tabular ml-auto">{formatCOP(row.amount)}</b>
                                </div>
                                <span className="text-[13px]">{row.concept}</span>
                                {row.attachment ? (
                                    <a href={row.attachment.url} className="inline-flex min-h-11 items-center gap-1.5 text-[13px] font-semibold text-fg">
                                        <Icon name="paperclip" size={13} />
                                        {row.attachment.name}
                                    </a>
                                ) : (
                                    <span className="text-xs text-fg-muted">{row.editable ? 'Sin soporte' : 'Se calcula solo'}</span>
                                )}
                            </li>
                        ))}
                    </ul>
                    <div className="flex flex-wrap items-baseline justify-between gap-2 border-t border-line bg-subtle px-4 py-3.5 md:px-5">
                        <span className="text-sm font-bold">Total del proyecto</span>
                        <span className="flex flex-col items-end">
                            <b className="tabular font-display text-2xl">{formatCOP(total)}</b>
                            {percent !== null ? (
                                <span className={cx('text-[13px]', percent >= 80 ? 'font-semibold text-status-maintenance' : 'text-fg-muted')}>
                                    {percent} % de {formatCOP(approvedCost)} aprobados
                                </span>
                            ) : null}
                        </span>
                    </div>
                    <p className="px-4 py-3 text-xs text-fg-muted md:px-5">
                        Tipos: licencia, hosting, consumo de IA, proveedor externo, equipo u otro. Cada costo puede ser único o mensual. El costo por hora interno solo lo ve quien
                        tiene permiso de costos.
                    </p>
                </section>
            </div>

            <AddHoursDialog open={hoursOpen} onClose={() => setHoursOpen(false)} props={props} />
            <AddCostDialog open={costOpen} onClose={() => setCostOpen(false)} projectKey={project.key} today={today} />
        </ProjectLayout>
    );
}
