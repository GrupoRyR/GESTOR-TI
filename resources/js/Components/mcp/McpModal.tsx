import { useId, type FormEvent, type ReactNode } from 'react';
import { useModalDialog } from '@/Components/ui/Dialog';
import { IconButton } from '@/Components/ui/IconButton';
import { cx } from '@/Components/ui/utils';

export interface McpModalProps {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    /** Under the title. */
    description?: ReactNode;
    /** Before the title (status tile). */
    leading?: ReactNode;
    /** Left side of the footer bar. */
    footnote?: ReactNode;
    actions: ReactNode;
    onSubmit?: (event: FormEvent<HTMLFormElement>) => void;
    /**
     * `false`: Esc, the backdrop and a close button do NOT close it; only an
     * explicit action does (the show-once token must not vanish by accident).
     */
    dismissible?: boolean;
    role?: 'dialog' | 'alertdialog';
    children: ReactNode;
}

/** E-McpCreate / E-McpCreated shell: header, body, grey footer bar with a note and the actions. */
export function McpModal({ open, onClose, title, description, leading, footnote, actions, onSubmit, dismissible = true, role, children }: McpModalProps) {
    const { ref, restoreFocus } = useModalDialog(open);
    const titleId = useId();
    const descriptionId = useId();

    const content = (
        <>
            <div className="flex items-start gap-3 px-5 pt-5">
                {leading}
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2 id={titleId} className="font-display text-xl leading-tight font-bold break-words">
                        {title}
                    </h2>
                    {description ? (
                        <div id={descriptionId} className="text-[13px] text-fg-muted">
                            {description}
                        </div>
                    ) : null}
                </div>
                {dismissible ? <IconButton icon="x" label="Cerrar" onClick={onClose} className="-mt-2 -mr-2" /> : null}
            </div>
            <div className="flex flex-col gap-4 px-5 pt-4 pb-5">{children}</div>
            <div className="flex flex-col-reverse gap-3 border-t border-line bg-ground px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                <span className="text-xs leading-snug text-fg-muted">{footnote}</span>
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">{actions}</div>
            </div>
        </>
    );

    return (
        <dialog
            ref={ref}
            role={role}
            aria-labelledby={titleId}
            aria-describedby={description ? descriptionId : undefined}
            onClose={() => {
                if (open) onClose();
                restoreFocus();
            }}
            onCancel={(event) => {
                event.preventDefault();
                if (dismissible) onClose();
            }}
            onClick={(event) => {
                if (dismissible && event.target === event.currentTarget) onClose();
            }}
            className={cx(
                'm-auto max-h-[92dvh] w-[calc(100%-32px)] max-w-[620px] overflow-y-auto rounded-card border border-line bg-surface p-0 text-fg shadow-[0_24px_64px_rgb(23_25_28/0.28)] backdrop:bg-black/45',
            )}
        >
            {open ? (
                onSubmit ? (
                    <form onSubmit={onSubmit} noValidate>
                        {content}
                    </form>
                ) : (
                    content
                )
            ) : null}
        </dialog>
    );
}
