import { useForm } from '@inertiajs/react';
import { useEffect, type FormEvent, type ReactNode } from 'react';
import { firstName } from '@/Components/ai/triage/triage';
import { Button } from '@/Components/ui/Button';
import { Dialog } from '@/Components/ui/Dialog';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { TextArea } from '@/Components/ui/TextArea';
import { route } from '@/lib/route';
import type { RequestSummary } from '@/types/ai';

export interface AskInfoDialogProps {
    open: boolean;
    onClose: () => void;
    /** One request, or several from the inbox bulk bar. */
    requests: RequestSummary[];
}

function Consequence({ icon, children }: { icon: IconName; children: ReactNode }) {
    return (
        <li className="flex items-start gap-2.5">
            <Icon name={icon} size={16} className="mt-0.5 text-fg-soft" />
            <span>{children}</span>
        </li>
    );
}

/** "Pedir información": a question that reaches the requester and counts as first response. */
export function AskInfoDialog({ open, onClose, requests }: AskInfoDialogProps) {
    const form = useForm<{ question: string }>({ question: '' });
    const single = requests.length === 1 ? requests[0] : undefined;
    const name = single ? firstName(single.requester.name) : null;
    const keys = requests.map((request) => request.key);

    useEffect(() => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    }, [open, keys.join()]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.question.trim()) {
            form.setError('question', 'Escribe la pregunta.');
            return;
        }
        const options = { preserveScroll: true, onSuccess: onClose };
        if (single) {
            form.post(route('ai.requests.ask', { request: single.key }), options);
        } else {
            form.transform((data) => ({ ...data, keys }));
            form.post(route('ai.requests.bulk.ask'), options);
        }
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            onSubmit={submit}
            title="Pedir información"
            description={
                single
                    ? `${single.key} · ${single.title} · ${single.requester.name}`
                    : `${requests.length} solicitudes: ${keys.join(', ')}. Cada persona recibe la misma pregunta.`
            }
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" icon="chat" loading={form.processing}>
                        Enviar pregunta
                    </Button>
                </>
            }
        >
            <TextArea
                label={name ? `Pregunta para ${name}` : 'Pregunta'}
                rows={3}
                value={form.data.question}
                error={form.errors.question}
                onChange={(event) => {
                    form.setData('question', event.target.value);
                    form.clearErrors('question');
                }}
            />
            <ul className="flex flex-col gap-2 rounded-control bg-ground p-3.5 text-[13px] leading-snug text-fg-soft">
                <Consequence icon="telegram">Le llega por correo y por Telegram, con un botón para responder.</Consequence>
                <Consequence icon="info">
                    <span className="inline-flex flex-wrap items-center gap-1.5">
                        El estado pasa a <StatusChip status="needs_info" size="sm" />
                    </span>
                </Consequence>
                <Consequence icon="check">Cuenta como primera respuesta.</Consequence>
            </ul>
        </Dialog>
    );
}
