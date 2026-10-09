import { useForm } from '@inertiajs/react';
import { useRef, type FormEvent } from 'react';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { formatFileSize, formatShortDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { Comment } from '@/types/ai';

export interface RequestConversationProps {
    requestKey: string;
    comments: Comment[];
    /** Signed-in user, to label their own messages "Tú". */
    viewerId: number;
    canComment: boolean;
    /** Set on the phone copy only, so the in-page nav can jump to it. */
    id?: string;
    /** Label of the reply box; defaults to "Escribe a TI" (IT writes to the requester). */
    composerLabel?: string;
    /** Placeholder of the reply box. */
    composerPlaceholder?: string;
    /** Inside a tab panel: no card and no visible heading (the tab names it). */
    embedded?: boolean;
}

/** Public conversation of a request (requester, IT and followers) with the reply form. */
export function RequestConversation({
    requestKey,
    comments,
    viewerId,
    canComment,
    id,
    composerLabel = 'Escribe a TI',
    composerPlaceholder = 'Tu mensaje',
    embedded = false,
}: RequestConversationProps) {
    const form = useForm<{ body: string; attachments: File[] }>({ body: '', attachments: [] });
    const fileRef = useRef<HTMLInputElement>(null);
    const titleId = `${id ?? 'conversacion-d'}-t`;

    const send = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.body.trim() && form.data.attachments.length === 0) {
            form.setError('body', 'Escribe un mensaje o adjunta un archivo.');
            return;
        }
        form.post(route('ai.requests.comments.store', { request: requestKey }), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <section
            id={id}
            aria-labelledby={titleId}
            className={cx('flex scroll-mt-24 flex-col gap-3', !embedded && 'rounded-card border border-line bg-surface p-4 md:p-5')}
        >
            <h2 id={titleId} className={embedded ? 'sr-only' : 'text-base font-bold'}>
                Conversación
            </h2>
            {comments.length === 0 ? <p className="text-sm text-fg-muted">Todavía no hay mensajes.</p> : null}
            <ol className="flex flex-col gap-3">
                {comments.map((comment) => {
                    const mine = comment.author.id === viewerId;
                    return (
                        <li key={comment.id} className={cx('flex max-w-[88%] flex-col gap-1', mine ? 'self-end items-end' : 'self-start')}>
                            <span className="text-xs text-fg-muted">
                                {mine ? 'Tú' : comment.author.name}
                                {comment.is_it && !mine ? (
                                    <>
                                        {' · '}
                                        <b className="rounded-[6px] bg-subtle px-1.5 py-px text-[11px] text-fg-soft">TI</b>
                                    </>
                                ) : null}
                                {' · '}
                                {formatShortDate(comment.created_at)}
                            </span>
                            <p
                                className={cx(
                                    'rounded-[14px] px-3.5 py-2.5 text-sm leading-snug whitespace-pre-line',
                                    mine ? 'rounded-br-[4px] bg-fg text-on-fg' : 'rounded-bl-[4px] bg-subtle',
                                )}
                            >
                                {comment.body}
                            </p>
                        </li>
                    );
                })}
            </ol>

            {canComment ? (
                <form onSubmit={send} className="flex flex-col gap-2 border-t border-line pt-3">
                    <label htmlFor={`${titleId}-msg`} className="text-[13px] font-semibold">
                        {composerLabel}
                    </label>
                    <textarea
                        id={`${titleId}-msg`}
                        rows={2}
                        placeholder={composerPlaceholder}
                        value={form.data.body}
                        aria-invalid={form.errors.body ? true : undefined}
                        aria-describedby={`${titleId}-help`}
                        onChange={(event) => {
                            form.setData('body', event.target.value);
                            if (form.errors.body) form.clearErrors('body');
                        }}
                        className={cx(
                            'w-full resize-y rounded-control border bg-surface px-3.5 py-2.5 text-base text-fg placeholder:text-fg-muted focus-visible:outline-2 focus-visible:outline-offset-1',
                            form.errors.body ? 'border-status-failure' : 'border-line-strong',
                        )}
                    />
                    {form.errors.body ? (
                        <p className="flex items-center gap-1.5 text-[13px] font-semibold text-status-failure">
                            <Icon name="alert-triangle" size={14} />
                            {form.errors.body}
                        </p>
                    ) : null}
                    {form.data.attachments.length > 0 ? (
                        <ul aria-label="Archivos para enviar" className="flex flex-wrap gap-2">
                            {form.data.attachments.map((file, index) => (
                                <li key={`${file.name}-${index}`} className="flex h-9 items-center gap-1.5 rounded-chip border border-line pl-2.5 text-[13px]">
                                    <Icon name="paperclip" size={14} />
                                    <span className="max-w-[160px] truncate">{file.name}</span>
                                    <span className="tabular text-fg-muted">{formatFileSize(file.size)}</span>
                                    <button
                                        type="button"
                                        aria-label={`Quitar ${file.name}`}
                                        onClick={() => form.setData('attachments', form.data.attachments.filter((_, position) => position !== index))}
                                        className="flex size-9 items-center justify-center rounded-chip text-fg-soft hover:bg-subtle"
                                    >
                                        <Icon name="x" size={14} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    <div className="flex items-center gap-2">
                        <Button variant="secondary" icon="paperclip" onClick={() => fileRef.current?.click()}>
                            Adjuntar
                        </Button>
                        <input
                            ref={fileRef}
                            type="file"
                            multiple
                            tabIndex={-1}
                            aria-hidden="true"
                            className="sr-only"
                            onChange={(event) => {
                                const files = event.target.files ? Array.from(event.target.files) : [];
                                form.setData('attachments', [...form.data.attachments, ...files]);
                                event.target.value = '';
                            }}
                        />
                        <Button type="submit" icon="chat" loading={form.processing} className="ml-auto">
                            Enviar
                        </Button>
                    </div>
                    <p id={`${titleId}-help`} className="text-xs text-fg-muted">
                        Lo ven TI y las personas que siguen esta solicitud.
                    </p>
                </form>
            ) : (
                <p className="flex items-center gap-2 border-t border-line pt-3 text-[13px] text-fg-muted">
                    <Icon name="lock" size={14} />
                    Esta solicitud ya terminó; la conversación quedó cerrada.
                </p>
            )}
        </section>
    );
}
