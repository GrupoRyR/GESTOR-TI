import type { FormEvent } from 'react';
import { TimeEntryFields, useTimeEntryForm } from '@/Components/ai/projects/TimeEntryFields';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { route } from '@/lib/route';
import type { TimeEntriesCreateProps } from '@/types/pages/ai-projects';
import type { PageProps } from '@/types/shared';

const decimal = (value: number) => value.toLocaleString('es-CO', { maximumFractionDigits: 1 });

/** B-TimeEntryPhone: log hours done without the timer (meetings, calls, work outside the Gestor). */
export default function TimeEntriesCreate(props: PageProps<TimeEntriesCreateProps>) {
    const { projects, defaults, telegram_keys, today } = props;
    const { form, submit } = useTimeEntryForm(today, defaults);
    const project = projects.find((item) => item.key === form.data.project_key);
    const back = project ? route('ai.projects.costs', { project: project.key }) : route('ai.week.index');
    const requestKey = project ? telegram_keys[project.key] : undefined;
    const telegram = `/horas ${requestKey ?? 'GIA-139'} ${decimal(form.data.hours ?? 1)} ${form.data.note.trim().split(/\s+/).slice(0, 3).join(' ') || 'qué hiciste'}`;

    return (
        <GestorLayout
            shared={props}
            title="Agregar horas · Gestor IA"
            tab="projects"
            mode="it"
            breadcrumb={[...(project ? [{ label: `${project.key} · Tiempo y costos`, href: back }] : [{ label: 'Mi semana', href: back }]), { label: 'Agregar horas' }]}
        >
            <form
                noValidate
                onSubmit={(event: FormEvent<HTMLFormElement>) => {
                    event.preventDefault();
                    submit();
                }}
                className="mx-auto flex w-full max-w-[520px] flex-col gap-5"
            >
                <div className="flex flex-col gap-1.5">
                    <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em]">Agregar horas</h1>
                    <p className="text-sm leading-snug text-fg-soft">Para lo que hiciste sin cronómetro: reuniones, llamadas o trabajo fuera del Gestor.</p>
                </div>

                <TimeEntryFields form={form} today={today} projects={projects} tasks={project?.tasks ?? []} size="lg" />

                {form.recentlySuccessful ? (
                    <p role="status" className="flex items-center gap-1.5 text-sm font-semibold text-status-active">
                        <Icon name="check-circle" size={16} />
                        Horas guardadas.
                    </p>
                ) : null}

                <div className="flex flex-col-reverse gap-2 sm:flex-row">
                    <Button variant="secondary" size="lg" href={back} className="sm:flex-1">
                        Cancelar
                    </Button>
                    <Button type="submit" size="lg" loading={form.processing} className="sm:flex-[2]">
                        Guardar
                    </Button>
                </div>

                <p className="flex items-start gap-2 rounded-card bg-subtle p-4 text-[13px] leading-snug text-fg-soft">
                    <Icon name="telegram" size={16} className="mt-0.5 shrink-0" />
                    <span>
                        También desde Telegram: <code className="rounded-[6px] bg-surface px-1.5 py-0.5 font-mono text-[12px] break-all text-fg">{telegram}</code>
                    </span>
                </p>
            </form>
        </GestorLayout>
    );
}
