import { useForm } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { PersonLine } from '@/Components/ai/projects/ProjectParts';
import { recipientCosts, recipientsOf, type ReportVersion } from '@/Components/ai/projects/projects';
import { ReportEmail } from '@/Components/ai/projects/ReportEmail';
import { Button } from '@/Components/ui/Button';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { cx } from '@/Components/ui/utils';
import { ProjectLayout } from '@/Layouts/ProjectLayout';
import { can } from '@/lib/can';
import { formatFileSize, formatLongDate, formatShortDate, formatTime } from '@/lib/format';
import { route } from '@/lib/route';
import type { ProjectsReportProps, ReportPayload, ReportRecipient, ReportSection } from '@/types/pages/ai-projects';
import type { PageProps } from '@/types/shared';

const WEEKDAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

const SECTIONS: { key: ReportSection; label: string }[] = [
    { key: 'status', label: 'Estado y avance' },
    { key: 'done', label: 'Hecho esta semana' },
    { key: 'next', label: 'Lo que sigue' },
    { key: 'blockers', label: 'Bloqueos y requerimientos' },
    { key: 'budget', label: 'Estimado contra real' },
];

/** "lunes 5 oct, 7:00 a. m.". */
const sentLabel = (iso: string) => `${formatLongDate(iso).split(' ')[0]} ${formatShortDate(iso)}, ${formatTime(iso)}`;

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <fieldset className="flex flex-col gap-2 rounded-card border border-line bg-surface p-4">
            <legend className="float-left mb-1 w-full text-sm font-bold">{title}</legend>
            {children}
        </fieldset>
    );
}

/** B-ProjectReport: who gets the report, how often, what it includes, the e-mail preview and the PDF. */
export default function ProjectsReport(props: PageProps<ProjectsReportProps>) {
    const { project, recipient_candidates, schedule, content, pdf, companies, auth } = props;
    const canViewCosts = can(auth.user, 'ai.costs.view');
    const company = companies.find((item) => item.id === content.company_id);
    const [recipients, setRecipients] = useState<ReportRecipient[]>(props.recipients);
    const [confirmSend, setConfirmSend] = useState(false);
    const form = useForm<Omit<ReportPayload, 'recipients'>>({
        periodicity: schedule.periodicity,
        weekday: schedule.weekday,
        time: schedule.time,
        sections: props.sections,
        version: 'internal',
    });
    const candidates = recipient_candidates.filter((candidate) => !recipients.some((item) => item.person.id === candidate.person.id));
    /** Without cost permission the sender can only send the version without time and cost. */
    const effective = canViewCosts ? recipients : recipients.map((recipient) => ({ ...recipient, include_costs: false }));
    const previewTo = recipientsOf(effective, form.data.version);
    const subject = `${project.key} ${content.project.name.split(' · ')[0]}: informe al ${formatShortDate(content.as_of)}`;

    const payload = (): ReportPayload => ({
        ...form.data,
        recipients: effective.map((recipient) => ({ user_id: recipient.person.id, include_costs: recipientCosts(recipient, 'internal').checked })),
    });

    const toggleSection = (key: ReportSection, checked: boolean) =>
        form.setData('sections', checked ? [...form.data.sections, key] : form.data.sections.filter((item) => item !== key));

    const send = () => {
        form.transform(payload);
        form.post(route('ai.projects.report.send', { project: project.key }), { preserveScroll: true, onFinish: () => setConfirmSend(false) });
    };

    const scheduleIt = () => {
        form.transform(payload);
        form.put(route('ai.projects.report.schedule', { project: project.key }), { preserveScroll: true });
    };

    const lastLine = [
        schedule.last_sent_at ? `Último envío: ${sentLabel(schedule.last_sent_at)}` : 'Aún no se ha enviado',
        schedule.periodicity === 'weekly' && schedule.next_send_at ? `próximo: ${formatLongDate(schedule.next_send_at).split(' ')[0]} ${formatShortDate(schedule.next_send_at)}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <ProjectLayout shared={props} title={`Informe ${project.key} · Gestor IA`} project={project} tab="report">
            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h2 className="font-display text-xl font-bold md:text-2xl">Informe del proyecto</h2>
                        <p className="text-[13px] text-fg-muted">{lastLine}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" icon="calendar" onClick={scheduleIt} loading={form.processing && !confirmSend} disabled={form.data.periodicity === 'manual'}>
                            Programar
                        </Button>
                        <Button icon="message" onClick={() => setConfirmSend(true)} disabled={recipients.length === 0}>
                            Enviar ahora
                        </Button>
                    </div>
                </div>
                {form.recentlySuccessful ? (
                    <p role="status" className="flex items-center gap-1.5 text-sm font-semibold text-status-active">
                        <Icon name="check-circle" size={16} />
                        Listo.
                    </p>
                ) : null}

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-[280px_minmax(0,600px)_minmax(0,1fr)] xl:items-start">
                    {/* Settings */}
                    <div className="flex flex-col gap-4 md:grid md:grid-cols-2 xl:flex">
                        <Block title="Destinatarios">
                            <ul className="flex flex-col divide-y divide-line">
                                {recipients.map((recipient) => {
                                    const costs = recipientCosts(recipient, form.data.version);
                                    const blockedBySender = !canViewCosts && !costs.disabled;
                                    const reasonId = `reason-${recipient.person.id}`;
                                    return (
                                        <li key={recipient.person.id} className="flex flex-col py-1.5">
                                            <PersonLine
                                                person={recipient.person}
                                                detail={recipient.role_label}
                                                trailing={
                                                    <button
                                                        type="button"
                                                        onClick={() => setRecipients((list) => list.filter((item) => item.person.id !== recipient.person.id))}
                                                        aria-label={`Quitar a ${recipient.person.name}`}
                                                        title="Quitar"
                                                        className="flex size-11 shrink-0 items-center justify-center rounded-control text-fg-muted hover:bg-subtle hover:text-fg"
                                                    >
                                                        <Icon name="x" size={14} />
                                                    </button>
                                                }
                                            />
                                            <label className={cx('flex min-h-11 items-center gap-2.5 pl-1 text-[13px]', costs.disabled || blockedBySender ? 'text-fg-muted' : 'cursor-pointer font-semibold')}>
                                                <input
                                                    type="checkbox"
                                                    checked={costs.checked && canViewCosts}
                                                    disabled={costs.disabled || blockedBySender}
                                                    aria-describedby={costs.reason || blockedBySender ? reasonId : undefined}
                                                    onChange={(event) =>
                                                        setRecipients((list) =>
                                                            list.map((item) => (item.person.id === recipient.person.id ? { ...item, include_costs: event.target.checked } : item)),
                                                        )
                                                    }
                                                    className="size-5 accent-brand-button disabled:cursor-not-allowed"
                                                />
                                                Tiempo y costo
                                            </label>
                                            {costs.reason || blockedBySender ? (
                                                <p id={reasonId} className="flex items-start gap-1.5 pl-1 text-xs leading-snug text-fg-muted">
                                                    <Icon name="lock" size={12} className="mt-0.5" />
                                                    {costs.reason ?? 'Solo quien tiene permiso de costos puede incluir tiempo y costo.'}
                                                </p>
                                            ) : null}
                                        </li>
                                    );
                                })}
                            </ul>
                            {candidates.length > 0 ? (
                                <label className="relative inline-flex h-11 items-center gap-1.5 self-start rounded-control px-2 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg">
                                    <Icon name="plus" size={14} strokeWidth={2.4} />
                                    Agregar destinatario
                                    <select
                                        aria-label="Agregar destinatario"
                                        value=""
                                        onChange={(event) => {
                                            const found = candidates.find((candidate) => candidate.person.id === Number(event.target.value));
                                            if (found) setRecipients((list) => [...list, found]);
                                        }}
                                        className="absolute inset-0 cursor-pointer appearance-none opacity-0"
                                    >
                                        <option value="">Elige a alguien</option>
                                        {candidates.map((candidate) => (
                                            <option key={candidate.person.id} value={candidate.person.id}>
                                                {candidate.person.name} · {candidate.role_label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            ) : null}
                        </Block>

                        <div className="flex flex-col gap-4">
                            <Block title="Periodicidad">
                                {(['manual', 'weekly'] as const).map((value) => (
                                    <label key={value} className="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold">
                                        <input
                                            type="radio"
                                            name="periodicity"
                                            value={value}
                                            checked={form.data.periodicity === value}
                                            onChange={() => form.setData('periodicity', value)}
                                            className="size-5 accent-brand-button"
                                        />
                                        {value === 'manual' ? 'Manual, cuando lo envíe' : 'Semanal'}
                                    </label>
                                ))}
                                {form.data.periodicity === 'weekly' ? (
                                    <div className="grid grid-cols-[minmax(0,1fr)_112px] gap-2 pl-8">
                                        <label className="flex flex-col gap-1 text-xs font-semibold text-fg-muted">
                                            Día
                                            <select
                                                value={form.data.weekday}
                                                onChange={(event) => form.setData('weekday', Number(event.target.value))}
                                                className="h-11 rounded-control border border-line-strong bg-surface px-2.5 text-sm font-semibold text-fg capitalize"
                                            >
                                                {WEEKDAYS.map((day, index) => (
                                                    <option key={day} value={index + 1}>
                                                        {day}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <label className="flex flex-col gap-1 text-xs font-semibold text-fg-muted">
                                            Hora
                                            <input
                                                type="time"
                                                value={form.data.time}
                                                onChange={(event) => form.setData('time', event.target.value)}
                                                className="h-11 rounded-control border border-line-strong bg-surface px-2 text-sm font-semibold text-fg"
                                            />
                                        </label>
                                    </div>
                                ) : null}
                            </Block>

                            <Block title="Qué incluye">
                                {SECTIONS.map((section) => {
                                    const locked = section.key === 'budget' && !canViewCosts;
                                    return (
                                        <label key={section.key} className={cx('flex min-h-11 items-center gap-3 text-sm', locked ? 'text-fg-muted' : 'cursor-pointer font-semibold')}>
                                            <input
                                                type="checkbox"
                                                checked={form.data.sections.includes(section.key) && !locked}
                                                disabled={locked}
                                                onChange={(event) => toggleSection(section.key, event.target.checked)}
                                                className="size-5 accent-brand-button"
                                            />
                                            {section.label}
                                            {section.key === 'budget' ? <Icon name="lock" size={13} label="Solo en la versión interna, con permiso de costos" /> : null}
                                        </label>
                                    );
                                })}
                            </Block>
                        </div>
                    </div>

                    {/* E-mail preview */}
                    <section aria-labelledby="preview" className="flex min-w-0 flex-col gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 id="preview" className="text-sm font-bold">
                                Vista previa del correo
                            </h3>
                            <SegmentedControl<ReportVersion>
                                label="Versión del informe"
                                size="sm"
                                value={form.data.version}
                                onChange={(value) => form.setData('version', value)}
                                options={[
                                    { value: 'internal', label: 'Interna' },
                                    { value: 'requester', label: 'Para el solicitante' },
                                ]}
                            />
                        </div>
                        <div className="overflow-hidden rounded-card border border-line bg-subtle">
                            <dl className="flex flex-col gap-1 border-b border-line bg-surface px-5 py-3 text-[13px]">
                                <div className="flex gap-1.5">
                                    <dt className="text-fg-muted">Para:</dt>
                                    <dd className="min-w-0 font-semibold">{previewTo.length > 0 ? previewTo.map((item) => item.person.name).join(', ') : 'Nadie recibe esta versión'}</dd>
                                </div>
                                <div className="flex gap-1.5">
                                    <dt className="text-fg-muted">Asunto:</dt>
                                    <dd className="min-w-0 font-semibold">{subject}</dd>
                                </div>
                            </dl>
                            <div className="p-2 sm:p-4">
                                <ReportEmail content={content} company={company} sections={form.data.sections} version={form.data.version} className="rounded-control border border-line" />
                            </div>
                        </div>
                    </section>

                    {/* PDF */}
                    <section aria-labelledby="pdf" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:max-w-[360px] xl:max-w-none">
                        <h3 id="pdf" className="text-sm font-bold">
                            PDF
                        </h3>
                        <div aria-hidden="true" className="mx-auto flex aspect-[210/297] w-full max-w-[200px] flex-col gap-2 rounded-[6px] border border-line bg-white p-3 shadow-card">
                            <span className="h-2 w-10 rounded-full bg-brand-button/70" />
                            <span className="text-[10px] leading-tight font-bold text-[#17191C]">{content.project.name}</span>
                            <span className="text-[8px] text-[#5E6168]">Informe al {formatShortDate(content.as_of)}</span>
                            {[80, 92, 64, 88, 70, 56].map((width, index) => (
                                <span key={index} className="h-1 rounded-full bg-[#E5E5E0]" style={{ width: `${width}%` }} />
                            ))}
                            {form.data.version === 'internal' && form.data.sections.includes('budget') && canViewCosts ? (
                                <span className="mt-1 flex flex-col gap-1">
                                    <span className="h-1.5 w-[74%] rounded-full bg-[#D69A00]" />
                                    <span className="h-1.5 w-[89%] rounded-full bg-[#D69A00]" />
                                </span>
                            ) : null}
                        </div>
                        <p className="text-center text-[13px] text-fg-soft">
                            <b className="text-fg">{content.project.name}</b>
                            <br />
                            Informe al {formatShortDate(content.as_of)}
                        </p>
                        <p className="text-center text-xs text-fg-muted">
                            {pdf.pages} {pdf.pages === 1 ? 'página' : 'páginas'} A4 · {formatFileSize(pdf.size)} · versión {form.data.version === 'internal' ? 'interna' : 'para el solicitante'}
                        </p>
                        <Button variant="secondary" icon="download" href={route('ai.projects.report.pdf', { project: project.key, version: form.data.version })} fullWidth>
                            Descargar PDF
                        </Button>
                        <p className="text-xs leading-snug text-fg-muted">El PDF sigue la versión elegida arriba. El del solicitante no trae tiempo ni costo.</p>
                    </section>
                </div>
            </div>

            <ConfirmDialog
                open={confirmSend}
                onClose={() => setConfirmSend(false)}
                onConfirm={send}
                tone="primary"
                processing={form.processing}
                title="¿Enviar el informe ahora?"
                description={`Les llega por correo a ${recipients.length} ${recipients.length === 1 ? 'persona' : 'personas'}: ${recipientsOf(effective, 'internal').length} con la versión interna y ${recipientsOf(effective, 'requester').length} con la versión para el solicitante. No se puede deshacer.`}
                confirmLabel="Enviar ahora"
            />
        </ProjectLayout>
    );
}
