import { useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { PersonPicker } from '@/Components/ai/projects/PersonPicker';
import { requirementState, respondDeadline } from '@/Components/ai/projects/projects';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { TextArea } from '@/Components/ui/TextArea';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { formatDateTime, formatFileSize, formatLongDate, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { RequirementsRespondProps } from '@/types/pages/ai-projects';
import type { PageProps } from '@/types/shared';

function ReassignForm({ props }: { props: PageProps<RequirementsRespondProps> }) {
    const { requirement, reassign_candidates, suggested_user_id, companies } = props;
    const [open, setOpen] = useState(false);
    const form = useForm<{ user_id: number | null; note: string }>({ user_id: suggested_user_id, note: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (form.data.user_id === null) {
            form.setError('user_id', 'Elige quién puede responder.');
            return;
        }
        form.transform((data) => ({ user_id: data.user_id, note: data.note.trim() || null }));
        form.post(route('ai.requirements.reassign', { requirement: requirement.id }), { preserveScroll: true });
    };

    return (
        <section aria-labelledby="not-mine" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
            <h2 id="not-mine" className="text-base font-bold">
                ¿No te corresponde?
            </h2>
            <p className="text-sm leading-snug text-fg-soft">Dinos quién puede responder y se lo pasamos. A TI le llega el aviso.</p>
            {open ? (
                <form onSubmit={submit} noValidate className="flex flex-col gap-3">
                    <PersonPicker
                        label="Persona sugerida"
                        options={reassign_candidates}
                        value={form.data.user_id}
                        suggestedId={suggested_user_id}
                        onChange={(id) => {
                            form.setData('user_id', id);
                            form.clearErrors('user_id');
                        }}
                        companyName={(id) => companies.find((company) => company.id === id)?.short_name}
                        hint="Busca a alguien de tu empresa"
                        error={form.errors.user_id}
                    />
                    <TextArea
                        label="Nota para TI"
                        optional
                        rows={2}
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                        placeholder="Ella maneja los usuarios de SAP"
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" variant="secondary" icon="user" loading={form.processing}>
                            Pasárselo
                        </Button>
                        <Button variant="quiet" onClick={() => setOpen(false)}>
                            Cancelar
                        </Button>
                    </div>
                </form>
            ) : (
                <Button variant="secondary" icon="user" onClick={() => setOpen(true)} className="self-start">
                    No me corresponde
                </Button>
            )}
        </section>
    );
}

/** B-RequirementRespond: what IT needs from a user, why, by when, and how to answer or pass it on. Phone-first. */
export default function RequirementsRespond(props: PageProps<RequirementsRespondProps>) {
    const { requirement, today, max_upload_mb } = props;
    const fileRef = useRef<HTMLInputElement>(null);
    const form = useForm<{ answer: string; attachment: File | null }>({ answer: '', attachment: null });
    const answered = requirement.status === 'answered';
    const deadline = respondDeadline(requirement, today);
    const { days } = requirementState(requirement, today);
    const late = !answered && days < 0;
    const dueSoon = !answered && days >= 0 && days <= 3;
    /** "viernes 9 oct". */
    const dueDay = `${formatLongDate(requirement.due_date).split(' ')[0]} ${formatShortDate(requirement.due_date)}`;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.answer.trim() && !form.data.attachment) {
            form.setError('answer', 'Escribe tu respuesta o adjunta un archivo.');
            return;
        }
        const file = form.data.attachment;
        if (file && file.size > max_upload_mb * 1024 * 1024) {
            form.setError('attachment', `El archivo pesa más de ${max_upload_mb} MB.`);
            return;
        }
        form.post(route('ai.requirements.answer', { requirement: requirement.id }), { forceFormData: true, preserveScroll: true });
    };

    return (
        <GestorLayout
            shared={props}
            title={`${requirement.title} · Gestor IA`}
            mode="requester"
            requesterNav="home"
            breadcrumb={[{ label: 'Inicio', href: route('home') }, { label: 'Te piden algo desde TI' }]}
        >
            <div className="mx-auto flex w-full max-w-[560px] flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <span className="text-[13px] font-semibold text-fg-muted">Te piden algo desde TI</span>
                    <h1 className="font-display text-[24px] leading-[1.15] font-bold tracking-[-0.02em] md:text-[28px]">
                        TI necesita esto para avanzar con {requirement.project.name}
                    </h1>
                    <p className="text-[15px] leading-normal text-fg-soft">{requirement.project.context}</p>
                </div>

                <section aria-labelledby="what" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
                    <h2 id="what" className="text-xs font-semibold text-fg-muted">
                        Qué se necesita
                    </h2>
                    <p className="text-lg leading-snug font-bold">{requirement.title}</p>
                    {requirement.detail ? <p className="text-sm leading-relaxed text-fg-soft">{requirement.detail}</p> : null}
                    {!answered ? (
                        <p
                            className={cx(
                                'flex flex-wrap items-center gap-x-2 gap-y-0.5 rounded-control px-3 py-2.5 text-sm',
                                late ? 'bg-status-failure-bg text-status-failure' : dueSoon ? 'bg-status-maintenance-bg text-status-maintenance' : 'bg-subtle text-fg-soft',
                            )}
                        >
                            <Icon name={late ? 'alert-triangle' : 'calendar'} size={16} />
                            <b>{late ? `Era para el ${dueDay}` : `Responde antes del ${dueDay}`}</b>
                            <span>{deadline}</span>
                        </p>
                    ) : null}
                    <div className="flex items-center gap-2 border-t border-line pt-3 text-[13px] text-fg-soft">
                        <Avatar name={requirement.requested_by.name} size="xs" />
                        Lo pide {requirement.requested_by.name}, de TI
                    </div>
                </section>

                {answered ? (
                    <section aria-labelledby="answered" className="flex flex-col gap-3 rounded-card border border-status-active/30 bg-status-active-bg p-4 md:p-5">
                        <h2 id="answered" className="flex items-center gap-2 text-base font-bold text-status-active">
                            <Icon name="check-circle" size={18} />
                            Ya respondiste
                        </h2>
                        {requirement.answered_at ? <p className="text-[13px] text-fg-soft">El {formatDateTime(requirement.answered_at)}. TI ya recibió el aviso.</p> : null}
                        {requirement.answer ? <blockquote className="border-l-2 border-status-active pl-3 text-sm leading-snug">{requirement.answer}</blockquote> : null}
                        {requirement.answer_attachment ? (
                            <span className="inline-flex items-center gap-1.5 text-[13px] text-fg-soft">
                                <Icon name="paperclip" size={14} />
                                {requirement.answer_attachment.name} · {formatFileSize(requirement.answer_attachment.size)}
                            </span>
                        ) : null}
                        <Button variant="secondary" href={route('home')} className="self-start">
                            Volver al inicio
                        </Button>
                    </section>
                ) : (
                    <>
                        <form onSubmit={submit} noValidate className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
                            <TextArea
                                label="Tu respuesta"
                                rows={4}
                                value={form.data.answer}
                                error={form.errors.answer}
                                placeholder="El usuario es… y ya tiene el permiso."
                                onChange={(event) => {
                                    form.setData('answer', event.target.value);
                                    form.clearErrors('answer');
                                }}
                            />
                            <div className="flex flex-col gap-1">
                                <input
                                    ref={fileRef}
                                    type="file"
                                    accept=".pdf,image/*,.xls,.xlsx,.csv"
                                    className="sr-only"
                                    tabIndex={-1}
                                    aria-hidden="true"
                                    onChange={(event) => {
                                        form.setData('attachment', event.target.files?.[0] ?? null);
                                        form.clearErrors('attachment', 'answer');
                                    }}
                                />
                                {form.data.attachment ? (
                                    <span className="flex min-h-11 items-center gap-2 rounded-control bg-subtle px-3 text-[13px]">
                                        <Icon name="paperclip" size={14} />
                                        <b className="min-w-0 flex-1 truncate">{form.data.attachment.name}</b>
                                        {formatFileSize(form.data.attachment.size)}
                                        <button
                                            type="button"
                                            onClick={() => form.setData('attachment', null)}
                                            aria-label={`Quitar ${form.data.attachment.name}`}
                                            className="-mr-2 flex size-11 items-center justify-center rounded-control hover:bg-surface"
                                        >
                                            <Icon name="x" size={14} />
                                        </button>
                                    </span>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => fileRef.current?.click()}
                                        className="inline-flex h-11 items-center gap-1.5 self-start rounded-control px-2 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg"
                                    >
                                        <Icon name="paperclip" size={14} />
                                        Adjuntar archivo
                                    </button>
                                )}
                                <span className="text-xs text-fg-muted">PDF, imagen o Excel, hasta {max_upload_mb} MB</span>
                                {form.errors.attachment ? <span className="text-[13px] font-semibold text-status-failure">{form.errors.attachment}</span> : null}
                            </div>
                            <Button type="submit" size="lg" icon="message" fullWidth loading={form.processing}>
                                Enviar respuesta
                            </Button>
                        </form>

                        <ReassignForm props={props} />
                    </>
                )}
            </div>
        </GestorLayout>
    );
}
