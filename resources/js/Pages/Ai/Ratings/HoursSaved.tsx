import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/Components/ui/Button';
import { NumberStepper } from '@/Components/ui/NumberStepper';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { route } from '@/lib/route';
import type { RatingsHoursSavedProps } from '@/types/pages/ai-requester';
import type { PageProps } from '@/types/shared';

const QUICK: { value: number; label: string }[] = [
    { value: 0, label: 'Ninguna' },
    { value: 1, label: '1' },
    { value: 2, label: '2' },
    { value: 4, label: '4' },
    { value: 8, label: '8+' },
];

const MAX = 60;
const hours = (value: number) => `${value.toLocaleString('es-CO', { maximumFractionDigits: 1 })} h`;

function Bar({ label, value, max, strong }: { label: string; value: number; max: number; strong?: boolean }) {
    const width = max > 0 ? Math.max(4, Math.round((value / max) * 100)) : 4;
    return (
        <div className="grid grid-cols-[96px_minmax(0,1fr)_44px] items-center gap-3 text-sm">
            <span className="text-fg-soft">{label}</span>
            <span className="h-3 rounded-full bg-subtle">
                <span className={cx('block h-3 rounded-full', strong ? 'bg-fg' : 'bg-line-strong')} style={{ width: `${width}%` }} />
            </span>
            <b className="tabular text-right">{hours(value)}</b>
        </div>
    );
}

/** "30 días después": how many hours a week the delivery really saves. */
export default function RatingsHoursSaved(props: PageProps<RatingsHoursSavedProps>) {
    const { request, estimated_hours_per_week } = props;
    const form = useForm<{ hours_per_week: number | null }>({ hours_per_week: null });
    const value = form.data.hours_per_week;
    const max = Math.max(estimated_hours_per_week, value ?? 0);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (value === null) {
            form.setError('hours_per_week', 'Escribe cuántas horas, o marca «Ninguna».');
            return;
        }
        form.post(route('ai.ratings.hours.store', { request: request.key }));
    };

    const set = (next: number | null) => {
        form.setData('hours_per_week', next);
        if (form.errors.hours_per_week) form.clearErrors('hours_per_week');
    };

    return (
        <GestorLayout
            shared={props}
            title={`Horas que ahorra ${request.key} · Gestor IA`}
            mode="requester"
            breadcrumb={[{ label: 'Mis solicitudes', href: route('ai.requests.mine') }, { label: '30 días después' }]}
        >
            <form onSubmit={submit} noValidate className="mx-auto flex w-full max-w-[560px] flex-col gap-6">
                <div className="flex flex-col gap-2">
                    <p className="text-sm text-fg-soft">
                        Hace un mes entró a producción <b className="tabular text-fg">{request.key}</b>: {request.title}.
                    </p>
                    <h1 className="font-display text-[26px] leading-[1.15] font-bold tracking-[-0.02em] md:text-[32px]">
                        ¿Cuántas horas te ahorra por semana?
                    </h1>
                </div>

                <NumberStepper
                    label="Horas por semana"
                    value={value}
                    onChange={set}
                    min={0}
                    max={MAX}
                    step={0.5}
                    unit="h"
                    error={form.errors.hours_per_week}
                />
                <div role="group" aria-label="Respuestas rápidas" className="-mt-3 flex flex-wrap gap-2">
                    {QUICK.map((option) => {
                        const pressed = value === option.value;
                        return (
                            <button
                                key={option.value}
                                type="button"
                                aria-pressed={pressed}
                                aria-label={option.value === 0 ? 'Ninguna' : option.value === 8 ? '8 horas o más' : `${option.value} ${option.value === 1 ? 'hora' : 'horas'}`}
                                onClick={() => set(option.value)}
                                className={cx(
                                    'tabular inline-flex h-11 min-w-11 items-center justify-center rounded-chip border px-3.5 text-sm transition-colors duration-150',
                                    pressed ? 'border-fg bg-fg font-bold text-on-fg' : 'border-line-strong bg-surface font-semibold hover:border-fg-muted',
                                )}
                            >
                                {option.label}
                            </button>
                        );
                    })}
                </div>

                <section aria-label="Comparación" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
                    <p className="text-sm">
                        Cuando la pediste calculaste <b className="tabular">{hours(estimated_hours_per_week)}</b> a la semana.
                    </p>
                    <Bar label="Calculaste" value={estimated_hours_per_week} max={max} />
                    <Bar label="Hoy dices" value={value ?? 0} max={max} strong />
                    <p className="text-[13px] leading-snug text-fg-muted">
                        No pasa nada si es menos. Con esto TI aprende a calcular mejor y a priorizar lo que de verdad ahorra tiempo.
                    </p>
                </section>

                <Button type="submit" size="lg" fullWidth loading={form.processing}>
                    Enviar
                </Button>
            </form>
        </GestorLayout>
    );
}
