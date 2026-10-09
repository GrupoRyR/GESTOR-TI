import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { RatingScale } from '@/Components/ui/RatingScale';
import { TextArea } from '@/Components/ui/TextArea';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { RatingsCreateProps, RatingStorePayload } from '@/types/pages/ai-requester';
import type { PageProps } from '@/types/shared';

/** Calificar la entrega: two 1–5 questions and an optional comment. */
export default function RatingsCreate(props: PageProps<RatingsCreateProps>) {
    const { request } = props;
    const form = useForm<RatingStorePayload>({ need_score: null, ease_score: null, comment: '' });
    const ready = form.data.need_score !== null && form.data.ease_score !== null;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!ready) return;
        form.transform((values) => ({ ...values, comment: values.comment.trim() || null }));
        form.post(route('ai.ratings.store', { request: request.key }));
    };

    return (
        <GestorLayout
            shared={props}
            title={`Calificar ${request.key} · Gestor IA`}
            mode="requester"
            breadcrumb={[{ label: 'Mis solicitudes', href: route('ai.requests.mine') }, { label: 'Calificar la entrega' }]}
        >
            <form onSubmit={submit} noValidate className="mx-auto flex w-full max-w-[560px] flex-col gap-6">
                <div className="flex flex-col gap-2">
                    <span className="tabular text-[13px] text-fg-muted">
                        {request.key} · en producción desde el {formatShortDate(request.in_production_since)}
                    </span>
                    <h1 className="font-display text-[26px] leading-[1.15] font-bold tracking-[-0.02em] md:text-[32px]">{request.title}</h1>
                    <span className="flex items-center gap-1.5 text-sm text-fg-soft">
                        <Icon name="clock" size={14} />
                        Toma menos de un minuto
                    </span>
                </div>

                <RatingScale
                    legend="¿Resolvió tu necesidad?"
                    ends={['Nada', 'Por completo']}
                    value={form.data.need_score}
                    onChange={(value) => form.setData('need_score', value)}
                />
                <RatingScale
                    legend="¿Qué tan fácil es de usar?"
                    ends={['Muy difícil', 'Muy fácil']}
                    value={form.data.ease_score}
                    onChange={(value) => form.setData('ease_score', value)}
                />
                <TextArea
                    label="Comentario"
                    optional
                    rows={3}
                    placeholder="Qué te gustó o qué cambiarías"
                    value={form.data.comment}
                    onChange={(event) => form.setData('comment', event.target.value)}
                    error={form.errors.comment}
                />

                <div className="flex flex-col gap-2">
                    <Button type="submit" size="lg" fullWidth disabled={!ready} loading={form.processing}>
                        Enviar calificación
                    </Button>
                    {!ready ? (
                        <p aria-live="polite" className="text-center text-[13px] text-fg-muted">
                            Responde las dos preguntas para enviarla.
                        </p>
                    ) : null}
                </div>
            </form>
        </GestorLayout>
    );
}
