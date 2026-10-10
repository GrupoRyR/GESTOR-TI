import { useEffect, useId, useState, type DragEvent, type ReactNode } from 'react';
import { Icon } from './Icon';
import { cx } from './utils';

export interface FileDropProps {
    label: ReactNode;
    hint?: ReactNode;
    error?: ReactNode;
    /** e.g. "image/svg+xml,image/png". */
    accept?: string;
    name?: string;
    /** Background the preview is judged against: `light` or `dark` (dark logo). */
    surface?: 'light' | 'dark';
    /** Existing file (e.g. the current logo URL). */
    previewUrl?: string | null;
    onFile?: (file: File | null) => void;
    disabled?: boolean;
    className?: string;
}

/**
 * Dashed drop area backed by a real file input: click, keyboard (the input
 * is focusable) or drag and drop. Images get a local preview.
 */
export function FileDrop({
    label,
    hint,
    error,
    accept,
    name,
    surface = 'light',
    previewUrl,
    onFile,
    disabled = false,
    className,
}: FileDropProps) {
    const id = useId();
    const hintId = hint ? `${id}-hint` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const [dragging, setDragging] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [localUrl, setLocalUrl] = useState<string | null>(null);

    useEffect(() => {
        if (!file || !file.type.startsWith('image/')) {
            setLocalUrl(null);
            return;
        }
        const url = URL.createObjectURL(file);
        setLocalUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);

    const choose = (next: File | null) => {
        setFile(next);
        onFile?.(next);
    };

    const onDrop = (event: DragEvent<HTMLLabelElement>) => {
        event.preventDefault();
        setDragging(false);
        if (disabled) return;
        choose(event.dataTransfer.files.item(0));
    };

    const preview = localUrl ?? previewUrl ?? null;
    const dark = surface === 'dark';

    return (
        <div className={cx('flex flex-col gap-1.5', className)}>
            <span className="text-[13px] font-semibold" id={`${id}-label`}>
                {label}
            </span>
            <label
                htmlFor={id}
                onDragOver={(event) => {
                    event.preventDefault();
                    if (!disabled) setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
                className={cx(
                    'relative flex min-h-[128px] cursor-pointer flex-col items-center justify-center gap-2 rounded-icon border-[1.5px] border-dashed px-4 py-5 text-center',
                    'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                    dark ? 'bg-[#17191c] text-[#f2f2ef]' : 'bg-surface text-fg',
                    error ? 'border-status-failure' : dragging ? 'border-fg' : dark ? 'border-[#3a3c42]' : 'border-line-strong',
                    disabled && 'cursor-not-allowed opacity-60',
                )}
            >
                <input
                    id={id}
                    name={name}
                    type="file"
                    accept={accept}
                    disabled={disabled}
                    aria-labelledby={`${id}-label`}
                    aria-describedby={[errorId, hintId].filter(Boolean).join(' ') || undefined}
                    aria-invalid={error ? true : undefined}
                    className="sr-only"
                    onChange={(event) => choose(event.target.files?.item(0) ?? null)}
                />
                {preview ? (
                    <img src={preview} alt="" className="max-h-16 w-auto max-w-full object-contain" />
                ) : (
                    <span className={cx('flex size-10 items-center justify-center rounded-control', dark ? 'bg-[#26272b]' : 'bg-subtle')}>
                        <Icon name="upload" size={20} />
                    </span>
                )}
                <span className="text-sm font-semibold">
                    {file ? file.name : preview ? 'Cambiar archivo' : 'Arrastra el archivo o haz clic para elegirlo'}
                </span>
                {dark ? <span className="text-xs text-[#a3a6ad]">Vista sobre fondo oscuro</span> : null}
            </label>
            {error ? (
                <p id={errorId} className="flex items-start gap-1.5 text-[13px] font-semibold text-status-failure">
                    <span className="mt-0.5">
                        <Icon name="alert-triangle" size={14} />
                    </span>
                    {error}
                </p>
            ) : null}
            {hint ? (
                <p id={hintId} className="text-[13px] leading-snug text-fg-muted">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}
