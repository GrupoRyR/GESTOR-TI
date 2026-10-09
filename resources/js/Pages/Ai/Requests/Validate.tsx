import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { validationState } from '@/Components/ai/requests/validation';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { TextArea } from '@/Components/ui/TextArea';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { route } from '@/lib/route';
import type { RequestsValidateProps } from '@/types/pages/ai-requester';
import type { PageProps } from '@/types/shared';

interface AnswerForm {
    id: number;
    result: 'pass' | 'fail' | null;
    comment: string;
    photo: File | null;
}

function Toggle({ pressed, tone, icon, label, onClick }: { pressed: boolean; tone: 'pass' | 'fail'; icon: 'check' | 'x'; label: string; onClick: () => void }) {
    return (
        <button
            type="button"
            aria-pressed={pressed}
            onClick={onClick}
            className={cx(
                'inline-flex h-12 flex-1 items-center justify-center gap-2 rounded-control border text-[15px] font-bold transition-colors duration-150',
                pressed
                    ? tone === 'pass'
                        ? 'border-status-active bg-status-active-bg text-status-active'
                        : 'border-status-failure bg-status-failure-bg text-status-failure'
                    : 'border-line-strong bg-surface text-fg hover:border-fg-muted',
            )}
        >
            <Icon name={icon} size={18} strokeWidth={2.6} />
            {label}
        </button>
    );
}

/** Validar: the requester tries the testing version and marks each acceptance criterion. */
export default function RequestsValidate(props: PageProps<RequestsValidateProps>) {
    const { request, criteria } = props;
    const form = useForm<{ criteria: AnswerForm[] }>({
        criteria: criteria.map((criterion) => ({ id: criterion.id, result: criterion.result, comment: criterion.comment ?? '', photo: null })),
    });
    const photoRefs = useRef<Record<number, HTMLInputElement | null>>({});
    const answers = form.data.criteria;
    const state = validationState(answers);
    const errors = form.errors as Record<string, string | undefined>;

    const change = (id: number, patch: Partial<AnswerForm>) => {
        form.setData(
            'criteria',
            answers.map((answer) => (answer.id === id ? { ...answer, ...patch } : answer)),
        );
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!state.canSubmit) return;
        form.transform((values) => ({
            criteria: values.criteria.map((answer) => ({
                id: answer.id,
                result: answer.result,
                comment: answer.result === 'fail' ? answer.comment.trim() : null,
                photo: answer.result === 'fail' ? answer.photo : null,
            })),
        }));
        form.put(route('ai.requests.validation.update', { request: request.key }), { forceFormData: true, preserveScroll: true });
    };

    return (
        <GestorLayout
            shared={props}
            title={`Validar ${request.key} · Gestor IA`}
            mode="requester"
            breadcrumb={[
                { label: 'Mis solicitudes', href: route('ai.requests.mine') },
                { label: request.key, href: route('ai.requests.show', { request: request.key }) },
                { label: 'Validar' },
            ]}
        >
            <form onSubmit={submit} noValidate className="mx-auto flex w-full max-w-[720px] flex-col gap-5">
                <section className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 md:p-5">
                    <div className="flex flex-wrap items-center gap-2">
                        <b className="tabular text-sm text-fg-soft">{request.key}</b>
                        <StatusChip status="in_testing" size="sm" />
                    </div>
                    <h1 className="font-display text-[24px] leading-[1.15] font-bold tracking-[-0.02em] md:text-[30px]">{request.title}</h1>
                    <p className="text-[15px] leading-normal text-fg-soft">
                        Prueba en la app y marca cada criterio. Si algo no cumple, cuéntanos qué viste y lo corregimos antes de publicarlo.
                    </p>
                    {request.product ? (
                        <div className="flex flex-col gap-1.5">
                            <a
                                href={request.product.url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex h-12 items-center justify-center gap-2 self-stretch rounded-control bg-fg px-4 text-[15px] font-bold text-on-fg no-underline hover:brightness-[1.15] sm:self-start"
                            >
                                Abrir {request.product.name}
                                <Icon name="external" size={16} />
                                <span className="sr-only">(se abre en otra pestaña)</span>
                            </a>
                            <span className="text-[13px] text-fg-muted">
                                Se abre la versión de pruebas, con datos de ejemplo. Lo que hagas ahí no afecta pedidos reales.
                            </span>
                        </div>
                    ) : null}
                </section>

                <div className="flex items-baseline justify-between gap-3">
                    <h2 className="text-lg font-bold">Criterios</h2>
                    <span className="tabular text-sm text-fg-muted">
                        {state.answered} de {state.total} respondidos
                    </span>
                </div>

                <ol className="flex flex-col gap-3">
                    {answers.map((answer, index) => {
                        const criterion = criteria[index];
                        const missingComment = answer.result === 'fail' && answer.comment.trim() === '';
                        return (
                            <li key={answer.id}>
                                <fieldset className={cx('flex flex-col gap-3 rounded-card border bg-surface p-4', answer.result === 'fail' ? 'border-status-failure-line' : 'border-line')}>
                                    <legend className="sr-only">Criterio {index + 1}</legend>
                                    <p className="grid grid-cols-[26px_minmax(0,1fr)] gap-2.5 text-[15px] leading-snug">
                                        <span aria-hidden="true" className="tabular flex size-[26px] items-center justify-center rounded-full bg-subtle text-[13px] font-bold">
                                            {index + 1}
                                        </span>
                                        <span>{criterion?.text}</span>
                                    </p>
                                    <div className="flex gap-2.5">
                                        <Toggle pressed={answer.result === 'pass'} tone="pass" icon="check" label="Cumple" onClick={() => change(answer.id, { result: 'pass' })} />
                                        <Toggle pressed={answer.result === 'fail'} tone="fail" icon="x" label="No cumple" onClick={() => change(answer.id, { result: 'fail' })} />
                                    </div>
                                    {answer.result === 'fail' ? (
                                        <>
                                            <TextArea
                                                label={
                                                    <>
                                                        ¿Qué falló? <span className="font-normal text-fg-muted">Obligatorio</span>
                                                    </>
                                                }
                                                required
                                                aria-required="true"
                                                rows={2}
                                                value={answer.comment}
                                                onChange={(event) => change(answer.id, { comment: event.target.value })}
                                                error={errors[`criteria.${index}.comment`]}
                                                hint={missingComment ? "Escribe qué viste para poder enviar la validación." : undefined}
                                            />
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Button variant="secondary" icon="image" onClick={() => photoRefs.current[answer.id]?.click()}>
                                                    {answer.photo ? 'Cambiar la foto' : 'Agregar una foto'}
                                                </Button>
                                                {answer.photo ? <span className="max-w-[200px] truncate text-[13px] text-fg-muted">{answer.photo.name}</span> : null}
                                                <input
                                                    ref={(node) => {
                                                        photoRefs.current[answer.id] = node;
                                                    }}
                                                    type="file"
                                                    accept="image/*"
                                                    tabIndex={-1}
                                                    aria-hidden="true"
                                                    className="sr-only"
                                                    onChange={(event) => change(answer.id, { photo: event.target.files?.item(0) ?? null })}
                                                />
                                            </div>
                                        </>
                                    ) : null}
                                </fieldset>
                            </li>
                        );
                    })}
                </ol>

                <div className="sticky bottom-[72px] z-10 -mx-4 flex flex-col gap-2 border-t border-line bg-surface px-4 py-3 md:bottom-0 md:mx-0 md:flex-row md:items-center md:rounded-card md:border">
                    <p aria-live="polite" className={cx('flex-1 text-sm', state.canSubmit ? 'font-semibold text-status-active' : 'text-fg-soft')}>
                        {state.message}
                    </p>
                    <Button type="submit" size="lg" disabled={!state.canSubmit} loading={form.processing} aria-disabled={!state.canSubmit}>
                        Enviar validación
                    </Button>
                </div>
            </form>
        </GestorLayout>
    );
}
