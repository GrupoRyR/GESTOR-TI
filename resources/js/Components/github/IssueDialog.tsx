import { useForm } from '@inertiajs/react';
import { useId, useState, type FormEvent } from 'react';
import { Button } from '@/Components/ui/Button';
import { Dialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { MarkdownPreview } from '@/Components/ui/MarkdownPreview';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { Select } from '@/Components/ui/Select';
import { TextField } from '@/Components/ui/TextField';
import { DEFAULT_ISSUE_LABEL, defaultIssueLabels, ISSUE_TITLE_MAX, issueBody, issueTitle } from '@/lib/issue';
import { slugify } from '@/lib/slug';
import type { IssueFormSetup, IssuePayload, TaskGitHub } from '@/types/pages/ai-github';

export interface IssueDialogProps {
    open: boolean;
    onClose: () => void;
    /** `ai.tasks.issue.store` for the task. */
    action: string;
    github: TaskGitHub & { repo: NonNullable<TaskGitHub['repo']> };
    form: IssueFormSetup;
    task: { key: string; title: string; description: string; labels: string[] };
    projectKey: string;
}

/**
 * D-TaskIssue: create the GitHub issue of a task. The repository is fixed
 * (the project's), the title carries the request key so PRs link back, and
 * the body never includes hours, costs, internal notes or vault data.
 */
export function IssueDialog({ open, onClose, action, github, form: setup, task, projectKey }: IssueDialogProps) {
    const form = useForm<IssuePayload>({
        title: issueTitle(github.request_key, task),
        body: issueBody({
            description: task.description,
            criteria: github.criteria.map((criterion) => criterion.text),
            link: { label: `Ver ${task.key} en el Gestor`, url: github.task_url },
            requestKey: github.request_key,
        }),
        labels: defaultIssueLabels(task.labels),
        assignee_id: setup.assignee_id,
    });
    const [mode, setMode] = useState<'preview' | 'markdown'>('preview');
    const bodyId = useId();
    const labelsId = useId();
    const addable = setup.label_options.map(slugify).filter((label, index, all) => label && !form.data.labels.includes(label) && all.indexOf(label) === index);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.title.trim()) {
            form.setError('title', 'Escribe el título del issue.');
            return;
        }
        form.post(action, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            size="lg"
            title="Crear issue en GitHub"
            onSubmit={submit}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" variant="dark" icon="issue" loading={form.processing}>
                        Crear issue
                    </Button>
                </>
            }
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-control bg-ground px-3.5 py-2.5 text-[13px]">
                <span className="text-fg-muted">Repositorio</span>
                <b className="min-w-0 break-all">{github.repo.full_name}</b>
                <span className="inline-flex items-center gap-1 text-fg-muted">
                    <Icon name="lock" size={12} />
                    Fijo: es el del proyecto {projectKey}
                </span>
            </div>

            <TextField
                label="Título"
                value={form.data.title}
                maxLength={ISSUE_TITLE_MAX}
                error={form.errors.title}
                onChange={(event) => {
                    form.setData('title', event.target.value);
                    form.clearErrors('title');
                }}
                hint={`Empieza con ${github.request_key} para que los PR se enlacen solos.`}
            />

            <div className="flex flex-col gap-1.5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span id={bodyId} className="text-[13px] font-semibold">
                        Descripción
                    </span>
                    <SegmentedControl
                        label="Ver la descripción como"
                        size="sm"
                        value={mode}
                        onChange={setMode}
                        options={[
                            { value: 'preview', label: 'Vista previa' },
                            { value: 'markdown', label: 'Markdown' },
                        ]}
                    />
                </div>
                {mode === 'preview' ? (
                    <div aria-labelledby={bodyId} role="region" className="max-h-[280px] overflow-y-auto rounded-control border border-line bg-surface px-4 py-3">
                        <MarkdownPreview source={form.data.body} emptyText="Sin descripción." className="text-sm" />
                    </div>
                ) : (
                    <textarea
                        aria-labelledby={bodyId}
                        value={form.data.body}
                        onChange={(event) => form.setData('body', event.target.value)}
                        rows={10}
                        spellCheck={false}
                        className="min-h-[200px] w-full rounded-control border border-line-strong bg-surface px-3.5 py-3 font-mono text-[13px] leading-relaxed"
                    />
                )}
                <p className="flex items-center gap-1.5 text-xs text-fg-muted">
                    <Icon name="shield" size={12} />
                    Nunca se envían horas, costos, notas internas ni datos de la bóveda.
                </p>
                {form.errors.body ? <p className="text-[13px] font-semibold text-status-failure">{form.errors.body}</p> : null}
            </div>

            <div className="flex flex-col gap-1.5">
                <span id={labelsId} className="text-[13px] font-semibold">
                    Etiquetas
                </span>
                <ul aria-labelledby={labelsId} className="flex flex-wrap items-center gap-1.5">
                    {form.data.labels.map((label) =>
                        label === DEFAULT_ISSUE_LABEL ? (
                            <li key={label} className="inline-flex h-9 items-center gap-1.5 rounded-chip bg-fg px-3 text-[13px] font-bold text-on-fg">
                                <Icon name="lock" size={12} />
                                {label}
                                <span className="sr-only"> (siempre va)</span>
                            </li>
                        ) : (
                            <li key={label} className="inline-flex h-9 items-center gap-0.5 rounded-chip border border-line-strong bg-surface pl-3 text-[13px] font-semibold">
                                {label}
                                <button
                                    type="button"
                                    aria-label={`Quitar etiqueta ${label}`}
                                    onClick={() => form.setData('labels', form.data.labels.filter((item) => item !== label))}
                                    className="flex size-9 items-center justify-center rounded-chip text-fg-muted hover:bg-subtle hover:text-fg"
                                >
                                    <Icon name="x" size={14} />
                                </button>
                            </li>
                        ),
                    )}
                    {addable.length > 0 ? (
                        <li>
                            <label className="relative inline-flex h-11 items-center gap-1.5 rounded-chip px-2.5 text-[13px] font-bold text-fg-soft hover:bg-subtle hover:text-fg">
                                <Icon name="plus" size={14} strokeWidth={2.4} />
                                Agregar
                                <select
                                    aria-label="Agregar etiqueta"
                                    value=""
                                    onChange={(event) => event.target.value && form.setData('labels', [...form.data.labels, event.target.value])}
                                    className="absolute inset-0 cursor-pointer appearance-none opacity-0"
                                >
                                    <option value="">Elige una etiqueta</option>
                                    {addable.map((label) => (
                                        <option key={label} value={label}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </li>
                    ) : null}
                </ul>
            </div>

            <Select
                label="Asignar a"
                value={form.data.assignee_id === null ? '' : String(form.data.assignee_id)}
                onChange={(event) => form.setData('assignee_id', event.target.value ? Number(event.target.value) : null)}
                options={[
                    ...setup.assignees.map((person) => ({
                        value: String(person.id),
                        label: person.github_login ? `${person.name} · @${person.github_login.replace(/^@/, '')}` : `${person.name} · sin cuenta de GitHub`,
                        disabled: person.github_login === null,
                    })),
                    { value: '', label: 'Sin asignar' },
                ]}
                error={form.errors.assignee_id}
            />
        </Dialog>
    );
}
