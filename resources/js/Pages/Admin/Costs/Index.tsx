import { router, useForm } from '@inertiajs/react';
import { Fragment, useState, type FormEvent, type ReactNode } from 'react';
import { AdminPageHeader } from '@/Components/admin/AdminParts';
import { CostsLock, PersonLine } from '@/Components/ai/projects/ProjectParts';
import { Button } from '@/Components/ui/Button';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { ConfirmDialog, Dialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { NoPermission } from '@/Components/ui/NoPermission';
import { Switch } from '@/Components/ui/Switch';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { can } from '@/lib/can';
import { formatCOP, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { PersonRef } from '@/types/ai';
import type { AdminCostsIndexProps, AreaValueRow, CostSettingsPayload, RatePayload, RateRow } from '@/types/pages/admin-costs';
import type { PageProps } from '@/types/shared';

const digits = (value: string) => {
    const only = value.replace(/\D/g, '');
    return only ? Number(only) : null;
};

const money = (value: number | null) => (value === null ? '' : value.toLocaleString('es-CO'));

function Panel({ title, description, aside, children, className }: { title: string; description?: string; aside?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cx('flex min-w-0 flex-col overflow-hidden rounded-card border border-line bg-surface', className)}>
            <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2 border-b border-line px-4 py-3.5 md:px-5">
                <div className="flex min-w-0 flex-col gap-1">
                    <h2 className="font-display text-lg leading-tight font-bold">{title}</h2>
                    {description ? <p className="text-[13px] leading-snug text-fg-muted">{description}</p> : null}
                </div>
                {aside}
            </div>
            {children}
        </section>
    );
}

function RateDialog({ row, today, onClose }: { row: RateRow | null; today: string; onClose: () => void }) {
    const form = useForm<RatePayload>({ rate: row?.rate ?? null, effective_from: today });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!row) return;
        if (form.data.rate === null || form.data.rate <= 0) {
            form.setError('rate', 'Pon el costo por hora en pesos.');
            return;
        }
        form.put(route('admin.costs.rates.update', { user: row.person.id }), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open={row !== null}
            onClose={onClose}
            size="sm"
            title={row ? `Cambiar tarifa de ${row.person.name}` : 'Cambiar tarifa'}
            description="Rige desde la fecha que elijas. Las horas ya cargadas conservan la tarifa con la que se registraron."
            onSubmit={submit}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Guardar tarifa
                    </Button>
                </>
            }
        >
            <TextField
                label="Costo interno por hora (pesos)"
                inputMode="numeric"
                value={money(form.data.rate)}
                error={form.errors.rate}
                hint={row ? `Hoy: ${formatCOP(row.rate)} / h` : undefined}
                onChange={(event) => {
                    form.setData('rate', digits(event.target.value));
                    form.clearErrors('rate');
                }}
            />
            <TextField label="Vigente desde" type="date" value={form.data.effective_from} error={form.errors.effective_from} onChange={(event) => form.setData('effective_from', event.target.value)} />
        </Dialog>
    );
}

function AreaValuesDialog({ open, rows, onClose, companyName }: { open: boolean; rows: AreaValueRow[]; onClose: () => void; companyName: (id: number) => string }) {
    const form = useForm<{ values: { area_id: number; value: number | null }[] }>({ values: rows.map((row) => ({ area_id: row.area.id, value: row.value })) });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.put(route('admin.costs.area-values.update'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Valor de la hora por área"
            description="Cuánto vale una hora de trabajo de cada área. Déjalo vacío si aún no se conoce: ese retorno no se calcula."
            onSubmit={submit}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={form.processing}>
                        Guardar valores
                    </Button>
                </>
            }
        >
            {rows.map((row, index) => (
                <TextField
                    key={row.area.id}
                    label={`${row.area.name} · ${companyName(row.area.company_id)}`}
                    inputMode="numeric"
                    placeholder="Sin valor"
                    value={money(form.data.values[index]?.value ?? null)}
                    error={form.errors[`values.${index}.value` as 'values']}
                    onChange={(event) =>
                        form.setData(
                            'values',
                            form.data.values.map((item, i) => (i === index ? { ...item, value: digits(event.target.value) } : item)),
                        )
                    }
                />
            ))}
        </Dialog>
    );
}

/** B-AdminRates: internal hourly rates, hour value per area, project approvers and budget alerts. */
export default function AdminCostsIndex(props: PageProps<AdminCostsIndexProps>) {
    const { rates, area_values, approvers, approver_candidates, settings, today, auth, companies } = props;
    const canView = can(auth.user, 'ai.costs.view');
    const canEditMoney = can(auth.user, 'ai.costs.manage');
    const canManage = can(auth.user, 'ai.manage');
    const [rateRow, setRateRow] = useState<RateRow | null>(null);
    const [valuesOpen, setValuesOpen] = useState(false);
    const [history, setHistory] = useState<number | null>(null);
    const [removing, setRemoving] = useState<PersonRef | null>(null);
    const form = useForm<CostSettingsPayload>({ ...settings });
    const company = (id: number) => companies.find((item) => item.id === id);

    if (!canView || rates === null || area_values === null) {
        return (
            <AdminLayout shared={props} title="Costos y aprobaciones · Administración" tab="costs">
                <NoPermission
                    title="Costos y aprobaciones es solo para quien tiene permiso de costos"
                    description="Aquí están las tarifas internas y el valor de la hora de cada área. Si necesitas entrar, pídele el permiso a Jhon Castro."
                />
            </AdminLayout>
        );
    }

    const saveSettings = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const threshold = form.data.reapproval_threshold_percent;
        if (!Number.isFinite(threshold) || threshold < 1 || threshold > 100) {
            form.setError('reapproval_threshold_percent', 'Usa un porcentaje entre 1 y 100.');
            return;
        }
        form.put(route('admin.costs.settings.update'), { preserveScroll: true });
    };

    const addApprover = (id: string) => {
        if (id) router.post(route('admin.costs.approvers.store'), { user_id: Number(id) }, { preserveScroll: true });
    };

    return (
        <AdminLayout shared={props} title="Costos y aprobaciones · Administración" tab="costs">
            <div className="flex flex-col gap-4">
                <AdminPageHeader title="Costos y aprobaciones" description="Tarifas, valor del tiempo de cada área, quién aprueba y cuándo avisar por presupuesto." />

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] xl:items-start">
                    <div className="flex min-w-0 flex-col gap-4">
                        <Panel title="Tarifas por hora" aside={<CostsLock />}>
                            <ul className="divide-y divide-line md:hidden">
                                {rates.map((row) => (
                                    <li key={row.person.id} className="flex flex-col gap-1 px-4 py-3">
                                        <PersonLine person={row.person} detail={row.person.job_title} />
                                        <p className="tabular text-sm">
                                            <b>{formatCOP(row.rate)} / h</b> <span className="text-fg-muted">desde el {formatShortDate(row.effective_from)}</span>
                                        </p>
                                        {row.history.length > 0 ? (
                                            <p className="tabular text-xs text-fg-muted">
                                                Antes: {row.history.map((change) => `${formatCOP(change.rate)} desde ${formatShortDate(change.effective_from)} ${change.effective_from.slice(0, 4)}`).join(' · ')}
                                            </p>
                                        ) : null}
                                        {canEditMoney ? (
                                            <Button variant="secondary" onClick={() => setRateRow(row)} className="self-start">
                                                Cambiar tarifa
                                            </Button>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                            <div className="hidden overflow-x-auto md:block">
                                <table className="w-full min-w-[560px] border-collapse text-sm">
                                    <caption className="sr-only">Costo interno por hora de cada persona</caption>
                                    <thead>
                                        <tr className="bg-subtle text-left text-xs font-bold text-fg-muted">
                                            <th scope="col" className="px-5 py-2.5">
                                                Persona
                                            </th>
                                            <th scope="col" className="px-3 py-2.5 text-right">
                                                Costo interno por hora
                                            </th>
                                            <th scope="col" className="px-3 py-2.5">
                                                Vigente desde
                                            </th>
                                            <th scope="col" className="px-3 py-2.5">
                                                Historial
                                            </th>
                                            <th scope="col" className="w-0 px-3 py-2.5">
                                                <span className="sr-only">Acciones</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rates.map((row) => (
                                            <Fragment key={row.person.id}>
                                                <tr className="border-t border-line">
                                                    <td className="px-5 py-1">
                                                        <PersonLine person={row.person} detail={row.person.job_title} />
                                                    </td>
                                                    <td className="tabular px-3 text-right font-bold">{formatCOP(row.rate)} / h</td>
                                                    <td className="tabular px-3 text-fg-soft">{formatShortDate(row.effective_from)}</td>
                                                    <td className="px-3">
                                                        {row.history.length > 0 ? (
                                                            <button
                                                                type="button"
                                                                aria-expanded={history === row.person.id}
                                                                onClick={() => setHistory((current) => (current === row.person.id ? null : row.person.id))}
                                                                className="inline-flex h-11 items-center gap-1 rounded-control px-1.5 text-[13px] font-semibold text-fg-soft hover:bg-subtle hover:text-fg"
                                                            >
                                                                <Icon name="clock" size={13} />
                                                                {row.history.length} {row.history.length === 1 ? 'cambio' : 'cambios'}
                                                                <Icon name="chevron-down" size={13} className={cx('transition-transform', history === row.person.id && 'rotate-180')} />
                                                            </button>
                                                        ) : (
                                                            <span className="text-[13px] text-fg-muted">Sin cambios</span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 text-right">
                                                        {canEditMoney ? (
                                                            <Button variant="secondary" onClick={() => setRateRow(row)} className="whitespace-nowrap">
                                                                Cambiar tarifa
                                                            </Button>
                                                        ) : null}
                                                    </td>
                                                </tr>
                                                {history === row.person.id ? (
                                                    <tr className="bg-subtle/60">
                                                        <td colSpan={5} className="px-5 py-2.5">
                                                            <ul className="flex flex-col gap-1 text-[13px] text-fg-soft">
                                                                {row.history.map((change) => (
                                                                    <li key={change.effective_from} className="tabular">
                                                                        {formatCOP(change.rate)} / h desde el {formatShortDate(change.effective_from)} de {change.effective_from.slice(0, 4)}
                                                                        {change.changed_by ? ` · la puso ${change.changed_by}` : ''}
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        </td>
                                                    </tr>
                                                ) : null}
                                            </Fragment>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <p className="border-t border-line px-4 py-3 text-xs leading-snug text-fg-muted md:px-5">
                                Un cambio rige desde la fecha que elijas; las horas ya cargadas conservan la tarifa con la que se registraron. Quien no tiene permiso de costos no ve esta
                                tabla ni los valores en los proyectos.
                            </p>
                        </Panel>

                        <Panel
                            title="Valor de la hora por área"
                            description="Se usa para calcular el retorno: horas que el área ahorra × este valor."
                            aside={
                                canEditMoney ? (
                                    <Button variant="secondary" icon="edit" onClick={() => setValuesOpen(true)}>
                                        Editar valores
                                    </Button>
                                ) : null
                            }
                        >
                            <ul className="divide-y divide-line">
                                {area_values.map((row) => {
                                    const owner = company(row.area.company_id);
                                    return (
                                        <li key={row.area.id} className="flex min-h-12 items-center gap-3 px-4 py-2 text-sm md:px-5">
                                            <b className="min-w-0 flex-1">{row.area.name}</b>
                                            <span className="flex w-28 items-center gap-1.5 text-[13px] text-fg-soft">
                                                {owner ? <CompanyMark company={owner} size={20} /> : null}
                                                {owner?.short_name}
                                            </span>
                                            <span className={cx('tabular w-28 text-right', row.value === null ? 'text-[13px] text-fg-muted' : 'font-bold')}>
                                                {row.value === null ? 'Sin valor' : formatCOP(row.value)}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </Panel>
                    </div>

                    <form onSubmit={saveSettings} noValidate className="flex min-w-0 flex-col gap-4">
                        <Panel title="Aprobadores de proyectos" description="Solo ellos aprueban proyectos y nuevas aprobaciones por presupuesto.">
                            <ul className="divide-y divide-line">
                                {approvers.map((approver) => (
                                    <li key={approver.person.id} className="px-4 py-1 md:px-5">
                                        <PersonLine
                                            person={approver.person}
                                            detail={approver.detail}
                                            trailing={
                                                canManage && approvers.length > 1 ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => setRemoving(approver.person)}
                                                        aria-label={`Quitar a ${approver.person.name} como aprobador`}
                                                        title="Quitar"
                                                        className="flex size-11 items-center justify-center rounded-control text-fg-muted hover:bg-subtle hover:text-status-failure"
                                                    >
                                                        <Icon name="x" size={16} />
                                                    </button>
                                                ) : null
                                            }
                                        />
                                    </li>
                                ))}
                            </ul>
                            <div className="flex flex-col gap-2 border-t border-line px-4 py-3 md:px-5">
                                {canManage && approver_candidates.length > 0 ? (
                                    <label className="relative inline-flex h-11 items-center gap-1.5 self-start rounded-control px-2 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg">
                                        <Icon name="plus" size={14} strokeWidth={2.4} />
                                        Agregar aprobador
                                        <select aria-label="Agregar aprobador" value="" onChange={(event) => addApprover(event.target.value)} className="absolute inset-0 cursor-pointer appearance-none opacity-0">
                                            <option value="">Elige a alguien</option>
                                            {approver_candidates.map((person) => (
                                                <option key={person.id} value={person.id}>
                                                    {person.name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                ) : null}
                                <Switch
                                    checked={form.data.require_other_approver}
                                    onChange={(checked) => form.setData('require_other_approver', checked)}
                                    disabled={!canManage}
                                    label="Si el aprobador es el mismo que evaluó, pedir la aprobación del otro"
                                />
                            </div>
                        </Panel>

                        <Panel title="Alertas de presupuesto">
                            <ul className="divide-y divide-line">
                                {[
                                    { mark: '80 %', text: 'Avisar cuando las horas o el costo lleguen al 80 %' },
                                    { mark: '100 %', text: 'Avisar al llegar al 100 %' },
                                ].map((alert) => (
                                    <li key={alert.mark} className="flex min-h-12 items-center gap-3 px-4 py-2 text-sm md:px-5">
                                        <b className="tabular w-12">{alert.mark}</b>
                                        <span className="min-w-0 flex-1">{alert.text}</span>
                                        <span className="inline-flex items-center gap-1 text-xs font-semibold text-fg-muted">
                                            <Icon name="lock" size={12} />
                                            Fijo
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <div className="flex flex-col gap-2 border-t border-line px-4 py-3 md:px-5">
                                <label htmlFor="threshold" className="text-sm font-semibold">
                                    Pedir nueva aprobación si lo real o la fecha suben más de
                                </label>
                                <div className="flex items-center gap-2">
                                    <input
                                        id="threshold"
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        max={100}
                                        disabled={!canManage}
                                        value={Number.isFinite(form.data.reapproval_threshold_percent) ? form.data.reapproval_threshold_percent : ''}
                                        aria-invalid={form.errors.reapproval_threshold_percent ? true : undefined}
                                        aria-describedby="threshold-help"
                                        onChange={(event) => {
                                            form.setData('reapproval_threshold_percent', event.target.value === '' ? Number.NaN : Number(event.target.value));
                                            form.clearErrors('reapproval_threshold_percent');
                                        }}
                                        className="tabular h-11 w-24 rounded-control border border-line-strong bg-surface px-3 text-right text-base font-bold disabled:bg-subtle"
                                    />
                                    <span className="text-sm font-bold">%</span>
                                </div>
                                {form.errors.reapproval_threshold_percent ? (
                                    <p className="text-[13px] font-semibold text-status-failure">{form.errors.reapproval_threshold_percent}</p>
                                ) : null}
                                <p id="threshold-help" className="text-xs leading-snug text-fg-muted">
                                    Los avisos llegan por correo y Telegram a los aprobadores y al líder del proyecto.
                                </p>
                            </div>
                        </Panel>

                        {canManage ? (
                            <div className="flex items-center justify-end gap-3">
                                {form.recentlySuccessful ? (
                                    <span role="status" className="text-[13px] font-semibold text-status-active">
                                        Cambios guardados.
                                    </span>
                                ) : null}
                                <Button type="submit" loading={form.processing} disabled={!form.isDirty}>
                                    Guardar cambios
                                </Button>
                            </div>
                        ) : (
                            <p className="flex items-center gap-1.5 text-[13px] text-fg-muted">
                                <Icon name="lock" size={13} />
                                Solo quien administra el Gestor IA cambia aprobadores y alertas.
                            </p>
                        )}
                    </form>
                </div>
            </div>

            <RateDialog key={rateRow?.person.id ?? 'none'} row={rateRow} today={today} onClose={() => setRateRow(null)} />
            <AreaValuesDialog open={valuesOpen} rows={area_values} onClose={() => setValuesOpen(false)} companyName={(id) => company(id)?.short_name ?? ''} />
            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={() => {
                    if (removing) router.delete(route('admin.costs.approvers.destroy', { user: removing.id }), { preserveScroll: true, onFinish: () => setRemoving(null) });
                }}
                title={removing ? `¿Quitar a ${removing.name} como aprobador?` : 'Quitar aprobador'}
                description="Deja de poder aprobar proyectos y nuevas aprobaciones por presupuesto. Lo que ya aprobó sigue aprobado."
                confirmLabel="Quitar aprobador"
            />
        </AdminLayout>
    );
}
