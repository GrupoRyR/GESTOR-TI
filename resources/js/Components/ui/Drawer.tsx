import { useId, type ReactNode } from 'react';
import { useModalDialog } from './Dialog';
import { IconButton } from './IconButton';
import { cx } from './utils';

export interface DrawerProps {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    /** Small line above the title, e.g. "T4 · P-07 Visualizador de cartera". */
    eyebrow?: ReactNode;
    /** Buttons next to the close button (e.g. open in a new page). */
    headerActions?: ReactNode;
    /** Sticky footer (e.g. Guardar). */
    footer?: ReactNode;
    /** Panel width from 768 px up (default 560). Full width below. */
    width?: number;
    children: ReactNode;
}

/**
 * Side panel on the right as a native modal `<dialog>`: focus stays inside
 * (native modal), Esc and the backdrop close it, and focus goes back to the
 * opener. Below 768 px it covers the whole screen.
 */
export function Drawer({ open, onClose, title, eyebrow, headerActions, footer, width = 560, children }: DrawerProps) {
    const { ref, restoreFocus } = useModalDialog(open);
    const titleId = useId();

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
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
            style={{ ['--drawer-width' as string]: `${width}px` }}
            className={cx(
                'm-0 ml-auto h-dvh max-h-none w-full max-w-none border-l border-line bg-surface p-0 text-fg backdrop:bg-black/35',
                'md:w-[min(var(--drawer-width),calc(100vw-48px))]',
            )}
        >
            {open ? (
                <div className="flex h-full flex-col">
                    <header className="flex items-start gap-2 border-b border-line px-4 py-3 md:px-5">
                        <div className="flex min-w-0 flex-1 flex-col gap-1 pt-1.5">
                            {eyebrow ? <div className="text-[13px] font-semibold text-fg-muted">{eyebrow}</div> : null}
                            <h2 id={titleId} className="font-display text-xl leading-tight font-bold">
                                {title}
                            </h2>
                        </div>
                        {headerActions}
                        <IconButton icon="x" label="Cerrar panel" onClick={onClose} />
                    </header>
                    <div className="flex flex-1 flex-col gap-5 overflow-y-auto px-4 py-5 md:px-5">{children}</div>
                    {footer ? (
                        <footer className="flex flex-wrap justify-end gap-2 border-t border-line px-4 py-3 pb-[max(12px,env(safe-area-inset-bottom))] md:px-5">
                            {footer}
                        </footer>
                    ) : null}
                </div>
            ) : null}
        </dialog>
    );
}
