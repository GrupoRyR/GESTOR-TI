import { useId, useRef, useState, type KeyboardEvent } from 'react';
import { applyMarkdownAction, type MarkdownAction } from '@/lib/markdownEdit';
import { Icon, type IconName } from './Icon';
import { MarkdownPreview } from './MarkdownPreview';
import { SegmentedControl } from './SegmentedControl';
import { cx } from './utils';

export type EditorMode = 'edit' | 'split' | 'preview';

const ACTIONS: { action: MarkdownAction; icon: IconName; label: string; shortcut?: string }[] = [
    { action: 'heading', icon: 'heading', label: 'Título' },
    { action: 'bold', icon: 'bold', label: 'Negrita', shortcut: 'Ctrl+B' },
    { action: 'italic', icon: 'italic', label: 'Cursiva', shortcut: 'Ctrl+I' },
    { action: 'bullet', icon: 'list', label: 'Lista' },
    { action: 'numbered', icon: 'list-ordered', label: 'Lista numerada' },
    { action: 'link', icon: 'link', label: 'Enlace', shortcut: 'Ctrl+K' },
    { action: 'table', icon: 'table', label: 'Tabla' },
    { action: 'code', icon: 'code', label: 'Código' },
];

const SHORTCUTS: Record<string, MarkdownAction> = { b: 'bold', i: 'italic', k: 'link' };

export interface MarkdownEditorProps {
    /** Accessible name of the text area: "Contenido del manual". */
    label: string;
    value: string;
    onChange: (value: string) => void;
    /** Controlled mode; uncontrolled starts on `split` from 1024 px designs. */
    mode?: EditorMode;
    onModeChange?: (mode: EditorMode) => void;
    defaultMode?: EditorMode;
    /** Minimum height of the writing area in px (default 420). */
    minHeight?: number;
    readOnly?: boolean;
    className?: string;
}

/**
 * Markdown editor: toolbar actions insert syntax around the selection,
 * Ctrl+B / Ctrl+I / Ctrl+K as shortcuts, and Editar · Dividido · Vista
 * previa. The preview is the same safe renderer readers see.
 */
export function MarkdownEditor({
    label,
    value,
    onChange,
    mode,
    onModeChange,
    defaultMode = 'split',
    minHeight = 420,
    readOnly = false,
    className,
}: MarkdownEditorProps) {
    const area = useRef<HTMLTextAreaElement>(null);
    const [ownMode, setOwnMode] = useState<EditorMode>(defaultMode);
    const current = mode ?? ownMode;
    const setMode = (next: EditorMode) => {
        if (mode === undefined) setOwnMode(next);
        onModeChange?.(next);
    };
    const textareaId = useId();

    const run = (action: MarkdownAction) => {
        const element = area.current;
        if (!element || readOnly) return;
        const result = applyMarkdownAction(value, element.selectionStart, element.selectionEnd, action);
        onChange(result.text);
        requestAnimationFrame(() => {
            element.focus();
            element.setSelectionRange(result.selectionStart, result.selectionEnd);
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (!(event.ctrlKey || event.metaKey)) return;
        const action = SHORTCUTS[event.key.toLowerCase()];
        if (action) {
            event.preventDefault();
            run(action);
        }
    };

    const lines = value.split('\n').length;

    return (
        <div className={cx('flex min-w-0 flex-col overflow-hidden rounded-card border border-line bg-surface', className)}>
            <div className="flex flex-wrap items-center gap-2 border-b border-line px-2 py-1.5">
                <div role="toolbar" aria-label="Formato" aria-controls={textareaId} className="flex flex-wrap items-center gap-0.5">
                    {ACTIONS.map((item) => (
                        <button
                            key={item.action}
                            type="button"
                            disabled={readOnly || current === 'preview'}
                            onClick={() => run(item.action)}
                            aria-label={item.shortcut ? `${item.label} (${item.shortcut})` : item.label}
                            title={item.shortcut ? `${item.label} (${item.shortcut})` : item.label}
                            className="flex size-11 items-center justify-center rounded-control text-fg-soft transition-colors duration-150 hover:bg-subtle hover:text-fg disabled:opacity-40"
                        >
                            <Icon name={item.icon} size={18} />
                        </button>
                    ))}
                </div>
                <span className="hidden text-xs text-fg-muted xl:inline">Markdown · se ve igual en la vista previa</span>
                <SegmentedControl<EditorMode>
                    label="Vista del editor"
                    size="sm"
                    value={current}
                    onChange={setMode}
                    className="ml-auto"
                    options={[
                        { value: 'edit', label: 'Editar' },
                        { value: 'split', label: 'Dividido' },
                        { value: 'preview', label: 'Vista previa' },
                    ]}
                />
            </div>
            <div className={cx('grid min-h-0', current === 'split' && 'lg:grid-cols-2')} style={{ minHeight }}>
                {current !== 'preview' ? (
                    <div className={cx('flex min-w-0', current === 'split' && 'border-b border-line lg:border-r lg:border-b-0')}>
                        <span
                            aria-hidden="true"
                            className="tabular hidden w-10 shrink-0 flex-col bg-subtle py-4 pr-2 text-right font-mono text-[13px] leading-6 text-fg-muted select-none sm:flex"
                        >
                            {Array.from({ length: lines }, (_, index) => (
                                <span key={index}>{index + 1}</span>
                            ))}
                        </span>
                        <textarea
                            ref={area}
                            id={textareaId}
                            aria-label={label}
                            value={value}
                            readOnly={readOnly}
                            spellCheck
                            // No soft wrap: each visual line matches its number in the gutter.
                            wrap="off"
                            onChange={(event) => onChange(event.target.value)}
                            onKeyDown={onKeyDown}
                            className="min-h-full w-full flex-1 resize-none bg-transparent px-4 py-4 font-mono text-[13px] leading-6 text-fg outline-none [field-sizing:content] focus-visible:outline-2 focus-visible:-outline-offset-2"
                        />
                    </div>
                ) : null}
                {current !== 'edit' ? (
                    <div className="min-w-0 overflow-y-auto px-5 py-4">
                        <MarkdownPreview source={value} />
                    </div>
                ) : null}
            </div>
        </div>
    );
}
