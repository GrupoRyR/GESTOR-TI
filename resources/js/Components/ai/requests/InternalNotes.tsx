import { useForm } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { Comment } from '@/types/ai';

export interface InternalNotesProps {
    requestKey: string;
    notes: Comment[];
    /** "Paola no ve estas notas." */
    requesterName: string;
    /** Mock clock for relative times. */
    now?: Date;
}

/** Notas internas: slate zone only IT sees, with its own form (`ai.requests.notes.store`). */
export function InternalNotes({ requestKey, notes, requesterName, now }: InternalNotesProps) {
    const form = useForm<{ body: string }>({ body: '' });
    const fieldId = useId();

    const save = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.body.trim()) {
            form.setError('body', 'Escribe la nota.');
            return;
        }
        form.post(route('ai.requests.notes.store', { request: requestKey }), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <div className="flex flex-col gap-3 rounded-control bg-status-local-bg p-3.5 md:p-4">
            <p className="flex flex-wrap items-center gap-2 text-[13px] text-status-local">
                <span className="inline-flex h-6 items-center gap-1 rounded-[7px] bg-status-local px-2 text-xs font-bold text-surface">
                    <Icon name="lock" size={12} strokeWidth={2.4} />
                    Solo TI
                </span>
                {requesterName} no ve estas notas.
            </p>
            {notes.length === 0 ? <p className="text-sm text-status-local">Todavía no hay notas internas.</p> : null}
            <ol className="flex flex-col gap-3">
                {notes.map((note) => (
                    <li key={note.id} className="flex gap-2.5">
                        <Avatar name={note.author.name} size="sm" className="bg-status-local! text-surface!" />
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <span className="text-[13px]">
                                <b>{note.author.name}</b> <span className="text-fg-muted">· {formatRelative(note.created_at, now)}</span>
                            </span>
                            <p className="text-sm leading-snug whitespace-pre-line">{note.body}</p>
                        </div>
                    </li>
                ))}
            </ol>
            <form onSubmit={save} className="flex flex-col gap-2 border-t border-status-local/20 pt-3">
                <label htmlFor={fieldId} className="text-[13px] font-semibold text-status-local">
                    Nueva nota interna
                </label>
                <textarea
                    id={fieldId}
                    rows={2}
                    placeholder="Escribe algo que solo vea TI"
                    value={form.data.body}
                    aria-invalid={form.errors.body ? true : undefined}
                    onChange={(event) => {
                        form.setData('body', event.target.value);
                        if (form.errors.body) form.clearErrors('body');
                    }}
                    className={cx(
                        'w-full resize-y rounded-control border bg-surface px-3.5 py-2.5 text-base text-fg placeholder:text-fg-muted md:text-sm',
                        form.errors.body ? 'border-status-failure' : 'border-status-local/30',
                    )}
                />
                {form.errors.body ? (
                    <p className="flex items-center gap-1.5 text-[13px] font-semibold text-status-failure">
                        <Icon name="alert-triangle" size={14} />
                        {form.errors.body}
                    </p>
                ) : null}
                <Button type="submit" variant="secondary" icon="lock" loading={form.processing} className="self-end">
                    Guardar nota interna
                </Button>
            </form>
        </div>
    );
}
