import { useEffect, useId, useRef, type FormEvent, type ReactNode, type RefObject } from 'react';
import { Button } from './Button';
import { IconButton } from './IconButton';
import { cx } from './utils';

/**
 * Opens/closes a native `<dialog>` as modal following `open`, remembering
 * the element that had focus so it gets it back on close. A modal dialog
 * already traps focus and makes the rest of the page inert.
 */
export function useModalDialog(open: boolean): { ref: RefObject<HTMLDialogElement | null>; restoreFocus: () => void } {
    const ref = useRef<HTMLDialogElement>(null);
    const opener = useRef<HTMLElement | null>(null);

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) {
            opener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();
        }
    }, [open]);

    return { ref, restoreFocus: () => opener.current?.focus() };
}

export type DialogSize = 'sm' | 'md' | 'lg';

const WIDTH: Record<DialogSize, string> = { sm: 'max-w-[440px]', md: 'max-w-[560px]', lg: 'max-w-[720px]' };

export interface DialogProps {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    description?: ReactNode;
    /** Footer buttons; usually Cancelar + the main action. */
    actions?: ReactNode;
    /** Wrap the body in a form; the main action should be `type="submit"`. */
    onSubmit?: (event: FormEvent<HTMLFormElement>) => void;
    size?: DialogSize;
    children?: ReactNode;
}

/**
 * Centered modal on a native `<dialog>`: Esc, the close button and a click
 * on the backdrop call `onClose`; focus returns to whatever opened it.
 */
export function Dialog({ open, onClose, title, description, actions, onSubmit, size = 'md', children }: DialogProps) {
    const { ref, restoreFocus } = useModalDialog(open);
    const titleId = useId();
    const descriptionId = useId();

    const body = (
        <>
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-1.5 pt-2">
                    <h2 id={titleId} className="font-display text-xl leading-tight font-bold">
                        {title}
                    </h2>
                    {description ? (
                        <div id={descriptionId} className="text-sm leading-normal text-fg-soft">
                            {description}
                        </div>
                    ) : null}
                </div>
                <IconButton icon="x" label="Cerrar" onClick={onClose} className="-mt-1 -mr-2" />
            </div>
            {children ? <div className="flex flex-col gap-4">{children}</div> : null}
            {actions ? <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">{actions}</div> : null}
        </>
    );

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            aria-describedby={description ? descriptionId : undefined}
            onClose={() => {
                if (open) onClose();
                restoreFocus();
            }}
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
            onClick={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
            className={cx(
                'm-auto max-h-[90dvh] w-[calc(100%-32px)] overflow-y-auto rounded-card border border-line bg-surface p-0 text-fg backdrop:bg-black/45',
                WIDTH[size],
            )}
        >
            {open ? (
                onSubmit ? (
                    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5 p-5 md:p-6">
                        {body}
                    </form>
                ) : (
                    <div className="flex flex-col gap-5 p-5 md:p-6">{body}</div>
                )
            ) : null}
        </dialog>
    );
}

export interface ConfirmDialogProps {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    title: ReactNode;
    description: ReactNode;
    confirmLabel: string;
    cancelLabel?: string;
    tone?: 'danger' | 'primary';
    processing?: boolean;
}

/** Yes/no confirmation for destructive or audited actions. */
export function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    title,
    description,
    confirmLabel,
    cancelLabel = 'Cancelar',
    tone = 'danger',
    processing = false,
}: ConfirmDialogProps) {
    return (
        <Dialog
            open={open}
            onClose={onClose}
            size="sm"
            title={title}
            description={description}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {cancelLabel}
                    </Button>
                    <Button variant={tone === 'danger' ? 'danger' : 'primary'} onClick={onConfirm} loading={processing}>
                        {confirmLabel}
                    </Button>
                </>
            }
        />
    );
}
