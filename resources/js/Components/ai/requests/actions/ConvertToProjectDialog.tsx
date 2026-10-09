import { useForm } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import { Button } from '@/Components/ui/Button';
import { Dialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { TextField } from '@/Components/ui/TextField';
import { route } from '@/lib/route';
import type { RequestSummary } from '@/types/ai';

export interface ConvertToProjectDialogProps {
    open: boolean;
    onClose: () => void;
    request: RequestSummary;
    /** Opens "Mandar a aprobación" instead, when the request is not approved yet. */
    onSendToApproval: () => void;
}

/** Approved work can become a project; anything else needs approval with an estimate first. */
export const CONVERTIBLE = new Set<RequestSummary['status']>(['approved', 'backlog']);

/** "Convertir en proyecto": name and start date for approved requests. */
export function ConvertToProjectDialog({ open, onClose, request, onSendToApproval }: ConvertToProjectDialogProps) {
    const form = useForm<{ name: string; start_date: string }>({ name: '', start_date: '' });
    const ready = CONVERTIBLE.has(request.status);

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData({ name: request.title, start_date: '' });
    }, [open, request.key]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const errors: Record<string, string> = {};
        if (!form.data.name.trim()) errors.name = 'Escribe el nombre del proyecto.';
        if (!form.data.start_date) errors.start_date = 'Elige cuándo empieza.';
        if (Object.keys(errors).length > 0) {
            form.setError(errors);
            return;
        }
        form.post(route('ai.requests.convert', { request: request.key }), { preserveScroll: true, onSuccess: onClose });
    };

    if (!ready) {
        return (
            <Dialog
                open={open}
                onClose={onClose}
                size="sm"
                title="Convertir en proyecto"
                description={`${request.key} · ${request.title}`}
                actions={
                    <>
                        <Button variant="secondary" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button icon="hourglass" onClick={onSendToApproval}>
                            Mandar a aprobación
                        </Button>
                    </>
                }
            >
                <div className="flex flex-col gap-2 rounded-control bg-ground p-3.5 text-sm leading-snug text-fg-soft">
                    <span className="flex flex-wrap items-center gap-1.5">
                        Hoy está en <StatusChip status={request.status} size="sm" />
                    </span>
                    <p>Para volverla proyecto primero necesita aprobación con estimado: horas por persona, costos y fecha de entrega.</p>
                </div>
            </Dialog>
        );
    }

    return (
        <Dialog
            open={open}
            onClose={onClose}
            onSubmit={submit}
            title="Convertir en proyecto"
            description={`${request.key} · ${request.title}`}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" icon="code" loading={form.processing}>
                        Crear proyecto
                    </Button>
                </>
            }
        >
            <TextField
                label="Nombre del proyecto"
                value={form.data.name}
                error={form.errors.name}
                hint="Corto y claro: así aparece en el tablero y en los avisos."
                onChange={(event) => {
                    form.setData('name', event.target.value);
                    form.clearErrors('name');
                }}
            />
            <TextField
                label="Empieza"
                type="date"
                value={form.data.start_date}
                error={form.errors.start_date}
                onChange={(event) => {
                    form.setData('start_date', event.target.value);
                    form.clearErrors('start_date');
                }}
            />
            <p className="flex items-start gap-2 text-[13px] leading-snug text-fg-muted">
                <Icon name="info" size={15} className="mt-0.5" />
                Se crea con su tablero, sus documentos y el presupuesto aprobado. La solicitud pasa a Planificada.
            </p>
        </Dialog>
    );
}
