import { useForm } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import { firstName } from '@/Components/ai/triage/triage';
import { Button } from '@/Components/ui/Button';
import { Dialog } from '@/Components/ui/Dialog';
import { Select } from '@/Components/ui/Select';
import { TextArea } from '@/Components/ui/TextArea';
import { route } from '@/lib/route';
import type { RequestSummary } from '@/types/ai';
import type { RejectPayload, RejectReason } from '@/types/pages/ai-triage';

export const REJECT_REASONS: { value: RejectReason; label: string }[] = [
    { value: 'exists', label: 'Ya existe una solución' },
    { value: 'not_it', label: 'No es un desarrollo de TI' },
    { value: 'cost', label: 'El costo supera el beneficio' },
    { value: 'duplicate', label: 'Duplicada' },
    { value: 'other', label: 'Otro motivo' },
];

export interface RejectDialogProps {
    open: boolean;
    onClose: () => void;
    request: RequestSummary;
}

/** "Rechazar con motivo": reason and a message for the requester, both required. */
export function RejectDialog({ open, onClose, request }: RejectDialogProps) {
    const form = useForm<RejectPayload>({ reason: '', message: '' });
    const name = firstName(request.requester.name);

    useEffect(() => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    }, [open, request.key]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const errors: Partial<Record<keyof RejectPayload, string>> = {};
        if (!form.data.reason) errors.reason = 'Elige el motivo.';
        if (!form.data.message.trim()) errors.message = `Escribe el mensaje para ${name}.`;
        if (Object.keys(errors).length > 0) {
            form.setError(errors);
            return;
        }
        form.post(route('ai.requests.reject', { request: request.key }), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            onSubmit={submit}
            title={`Rechazar ${request.key}`}
            description={`${request.title} · ${request.requester.name}`}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" icon="x-circle" loading={form.processing}>
                        Rechazar y avisar a {name}
                    </Button>
                </>
            }
        >
            <Select
                label="Motivo"
                placeholder="Elige un motivo"
                options={REJECT_REASONS}
                value={form.data.reason}
                error={form.errors.reason}
                onChange={(event) => {
                    form.setData('reason', event.target.value as RejectReason);
                    form.clearErrors('reason');
                }}
            />
            <TextArea
                label={`Mensaje para ${name}`}
                rows={4}
                value={form.data.message}
                error={form.errors.message}
                hint={`${name} lo recibe por correo y Telegram. El estado pasa a Rechazada con este motivo.`}
                onChange={(event) => {
                    form.setData('message', event.target.value);
                    form.clearErrors('message');
                }}
            />
        </Dialog>
    );
}
