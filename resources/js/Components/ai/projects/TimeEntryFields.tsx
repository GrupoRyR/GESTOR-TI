import { useForm } from '@inertiajs/react';
import { NumberStepper } from '@/Components/ui/NumberStepper';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { Select } from '@/Components/ui/Select';
import { TextArea } from '@/Components/ui/TextArea';
import { TextField } from '@/Components/ui/TextField';
import { formatLongDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { TaskRef, TimeEntryPayload } from '@/types/pages/ai-projects';
import { entryDate, type EntryDateChoice } from './projects';

export interface TimeEntryFormState {
    project_key: string;
    task_key: string;
    choice: EntryDateChoice;
    other_date: string;
    hours: number | null;
    note: string;
}

const capitalize = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

/** Form state, validation and submit for "Agregar horas" (dialog and phone page). */
export function useTimeEntryForm(today: string, defaults: { project_key: string | null; task_key: string | null }) {
    const form = useForm<TimeEntryFormState>({
        project_key: defaults.project_key ?? '',
        task_key: defaults.task_key ?? '',
        choice: 'today',
        other_date: '',
        hours: 1,
        note: '',
    });

    const submit = (onSuccess?: () => void) => {
        const errors: Partial<Record<keyof TimeEntryFormState, string>> = {};
        const date = entryDate(form.data.choice, today, form.data.other_date || null);
        if (!form.data.project_key) errors.project_key = 'Elige el proyecto.';
        if (!date) errors.other_date = 'Elige la fecha.';
        else if (date > today) errors.other_date = 'No se cargan horas de días que no han pasado.';
        if (form.data.hours === null || form.data.hours <= 0) errors.hours = 'Pon cuántas horas fueron.';
        else if (form.data.hours > 16) errors.hours = 'Máximo 16 horas en un día.';
        if (!form.data.note.trim()) errors.note = 'Cuenta en una línea qué hiciste.';
        if (Object.keys(errors).length > 0) {
            form.setError(errors);
            return;
        }
        form.transform(
            (data): TimeEntryPayload => ({ task_key: data.task_key || null, date: date!, hours: data.hours ?? 0, note: data.note.trim() }),
        );
        form.post(route('ai.time-entries.store', { project: form.data.project_key }), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('hours', 'note', 'choice', 'other_date');
                onSuccess?.();
            },
        });
    };

    return { form, submit };
}

export interface TimeEntryFieldsProps {
    form: ReturnType<typeof useTimeEntryForm>['form'];
    today: string;
    /** Project select (phone page). Omit to keep the project fixed (dialog inside a project). */
    projects?: { key: string; name: string; tasks: TaskRef[] }[];
    tasks: TaskRef[];
    /** 48 px controls on the phone page. */
    size?: 'md' | 'lg';
}

/** Project, task, Hoy / Ayer / Otra fecha, hours in half-hour steps and what was done. */
export function TimeEntryFields({ form, today, projects, tasks, size = 'md' }: TimeEntryFieldsProps) {
    const date = entryDate(form.data.choice, today, form.data.other_date || null);
    return (
        <div className="flex flex-col gap-4">
            {projects ? (
                <Select
                    label="Proyecto"
                    size={size}
                    value={form.data.project_key}
                    placeholder="Elige el proyecto"
                    options={projects.map((project) => ({ value: project.key, label: `${project.key} · ${project.name}` }))}
                    error={form.errors.project_key}
                    onChange={(event) => {
                        form.setData((data) => ({ ...data, project_key: event.target.value, task_key: '' }));
                        form.clearErrors('project_key');
                    }}
                />
            ) : null}
            <Select
                label="Tarea"
                optional
                size={size}
                value={form.data.task_key}
                placeholder="Sin tarea, solo el proyecto"
                options={tasks.map((task) => ({ value: task.key, label: `${task.key} · ${task.title}` }))}
                hint="Sin tarea, las horas quedan en el proyecto."
                onChange={(event) => form.setData('task_key', event.target.value)}
            />
            <div className="flex flex-col gap-1.5">
                <span className="text-[13px] font-semibold">Fecha</span>
                <SegmentedControl<EntryDateChoice>
                    label="Fecha"
                    value={form.data.choice}
                    onChange={(choice) => {
                        form.setData('choice', choice);
                        form.clearErrors('other_date');
                    }}
                    options={[
                        { value: 'today', label: 'Hoy' },
                        { value: 'yesterday', label: 'Ayer' },
                        { value: 'other', label: 'Otra fecha' },
                    ]}
                />
                {form.data.choice === 'other' ? (
                    <TextField
                        label="Qué día"
                        type="date"
                        size={size}
                        max={today}
                        value={form.data.other_date}
                        error={form.errors.other_date}
                        onChange={(event) => {
                            form.setData('other_date', event.target.value);
                            form.clearErrors('other_date');
                        }}
                    />
                ) : null}
                {date ? <span className="text-[13px] text-fg-muted">{capitalize(formatLongDate(date))}</span> : null}
            </div>
            <NumberStepper
                label="Horas"
                value={form.data.hours}
                onChange={(value) => {
                    form.setData('hours', value);
                    form.clearErrors('hours');
                }}
                min={0.5}
                max={16}
                step={0.5}
                unit="h"
                hint="Sube o baja de media hora en media hora"
                error={form.errors.hours}
            />
            <TextArea
                label="Qué hiciste"
                rows={3}
                value={form.data.note}
                error={form.errors.note}
                placeholder="Reunión con Contabilidad para pedir el acceso a la vista de cartera."
                onChange={(event) => {
                    form.setData('note', event.target.value);
                    form.clearErrors('note');
                }}
            />
        </div>
    );
}
