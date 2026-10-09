import { Link } from '@inertiajs/react';
import { useRef, useState, type ReactNode, type RefObject } from 'react';
import { Button } from '@/Components/ui/Button';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { StatusChip } from '@/Components/ui/StatusChip';
import { cx } from '@/Components/ui/utils';
import { formatFileSize } from '@/lib/format';
import { WIZARD_STEPS, type WizardStepNumber } from '@/lib/wizard';
import type { RequestSummary } from '@/types/ai';

// ---- chrome ---------------------------------------------------------------

export type SaveState = 'idle' | 'saving' | 'saved';

const SAVE_LABEL: Record<SaveState, string> = { idle: 'Se guarda solo', saving: 'Guardando…', saved: 'Guardado' };

/** Close button, "Paso N de 7", autosave status and the 7-segment progress. */
export function WizardHeader({ step, closeHref, save }: { step: WizardStepNumber; closeHref: string; save: SaveState }) {
    const title = WIZARD_STEPS[step - 1]?.title ?? '';
    return (
        <header className="sticky top-0 z-20 shrink-0 border-b border-line bg-surface">
            <div className="mx-auto flex w-full max-w-[720px] flex-col gap-2 px-2 pt-1.5 pb-3 md:px-6 md:pt-3">
                <div className="flex items-center gap-1">
                    <Link
                        href={closeHref}
                        aria-label="Cerrar. Tu borrador queda guardado"
                        className="flex size-11 shrink-0 items-center justify-center rounded-control text-fg hover:bg-subtle"
                    >
                        <Icon name="x" size={22} />
                    </Link>
                    <span className="text-sm font-bold">
                        Paso {step} de 7<span className="hidden font-semibold text-fg-muted md:inline"> · {title}</span>
                    </span>
                    <span aria-live="polite" className="ml-auto pr-2 text-xs text-fg-muted">
                        {SAVE_LABEL[save]}
                    </span>
                </div>
                <div
                    role="progressbar"
                    aria-valuemin={1}
                    aria-valuemax={7}
                    aria-valuenow={step}
                    aria-valuetext={`Paso ${step} de 7: ${title}`}
                    aria-label="Avance de la solicitud"
                    className="grid grid-cols-7 gap-1 px-2"
                >
                    {WIZARD_STEPS.map(({ number }) => (
                        <span key={number} className={cx('h-1 rounded-[2px]', number <= step ? 'bg-fg' : 'bg-line')} />
                    ))}
                </div>
            </div>
        </header>
    );
}

/** Sticky Atrás / Seguir bar. */
export function WizardFooter({ step, onBack, processing }: { step: WizardStepNumber; onBack: () => void; processing: boolean }) {
    return (
        <footer className="sticky bottom-0 z-20 shrink-0 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)]">
            <div className="mx-auto grid w-full max-w-[720px] grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-2.5 px-4 py-3 md:flex md:justify-end md:px-6">
                <Button variant="secondary" size="lg" onClick={onBack} disabled={step === 1} className="md:min-w-[120px]">
                    Atrás
                </Button>
                <Button type="submit" size="lg" loading={processing} className="md:min-w-[200px]">
                    {step === 7 ? 'Enviar solicitud' : 'Seguir'}
                </Button>
            </div>
        </footer>
    );
}

export function StepHeading({ title, text, headingRef }: { title: string; text?: ReactNode; headingRef: RefObject<HTMLHeadingElement | null> }) {
    return (
        <div className="flex flex-col gap-1.5">
            <h1
                ref={headingRef}
                tabIndex={-1}
                className="font-display text-[26px] leading-[1.15] font-bold tracking-[-0.02em] outline-none md:text-[32px]"
            >
                {title}
            </h1>
            {text ? <p className="text-[15px] leading-normal text-fg-soft">{text}</p> : null}
        </div>
    );
}

/** Left-ruled note: "Llegaste desde…", "¿Por qué preguntamos?". */
export function Note({ icon, children, tone = 'neutral' }: { icon: IconName; children: ReactNode; tone?: 'neutral' | 'brand' }) {
    return (
        <div
            className={cx(
                'flex items-start gap-2.5 rounded-control border border-l-4 border-line bg-surface p-3 text-[13px] leading-snug',
                tone === 'brand' ? 'border-l-brand-primary' : 'border-l-fg-muted',
            )}
        >
            <span className={cx('mt-px shrink-0', tone === 'brand' ? 'text-brand-ink' : 'text-fg-muted')}>
                <Icon name={icon} size={18} />
            </span>
            <span className="min-w-0">{children}</span>
        </div>
    );
}

// ---- similar request --------------------------------------------------------

export interface SimilarMatchProps {
    request: RequestSummary;
    onVote: () => void;
    onDismiss: () => void;
    processing: boolean;
}

/** "¿Es lo mismo que esta?" with +1 and "Es distinta". */
export function SimilarMatch({ request, onVote, onDismiss, processing }: SimilarMatchProps) {
    const people = request.votes;
    return (
        <section
            aria-labelledby="similar-title"
            className="flex flex-col gap-3 rounded-card border-2 border-status-maintenance-dot bg-status-maintenance-bg/60 p-4"
        >
            <div className="flex items-center gap-2 text-status-maintenance">
                <Icon name="copy" size={18} />
                <b id="similar-title" className="text-[15px]">
                    ¿Es lo mismo que esta?
                </b>
                <span className="ml-auto text-xs font-semibold">Se parece mucho</span>
            </div>
            <div className="flex flex-col gap-1.5 rounded-control border border-line bg-surface p-3">
                <div className="flex flex-wrap items-center gap-2">
                    <b className="tabular text-[13px] text-fg-soft">{request.key}</b>
                    <StatusChip status={request.status} size="sm" />
                </div>
                <b className="text-[15px] leading-snug">{request.title}</b>
                {people > 0 ? (
                    <span className="flex items-center gap-1.5 text-[13px] text-fg-muted">
                        <Icon name="employees" size={14} />
                        {people === 1 ? '1 persona' : `${people} personas`} de {request.area.name} ya la {people === 1 ? 'pidió' : 'pidieron'}
                    </span>
                ) : null}
            </div>
            <div className="grid grid-cols-2 gap-2.5">
                <Button variant="dark" onClick={onVote} loading={processing}>
                    +1 Es lo mismo
                </Button>
                <Button variant="secondary" onClick={onDismiss}>
                    Es distinta
                </Button>
            </div>
            <p className="text-[13px] leading-snug text-fg-soft">Con +1 sumas tu voto y te avisamos de sus avances. No tienes que llenar el resto.</p>
        </section>
    );
}

// ---- attachments ------------------------------------------------------------

const ACCEPT = 'image/*,application/pdf,.xls,.xlsx,.csv';

function extension(name: string): string {
    const dot = name.lastIndexOf('.');
    return dot > 0 ? name.slice(dot + 1).toUpperCase().slice(0, 4) : 'ARCH';
}

export interface AttachmentPickerProps {
    files: File[];
    onChange: (files: File[]) => void;
    maxMb: number;
    error?: string;
}

/** "Tomar foto" (camera on phones) and "Subir archivo", drag and drop, list with Quitar. */
export function AttachmentPicker({ files, onChange, maxMb, error }: AttachmentPickerProps) {
    const fileRef = useRef<HTMLInputElement>(null);
    const cameraRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const add = (list: FileList | null) => {
        if (!list || list.length === 0) return;
        onChange([...files, ...Array.from(list)]);
    };

    return (
        <div className="flex flex-col gap-2.5">
            <div
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDragging(false);
                    add(event.dataTransfer.files);
                }}
                className={cx(
                    'flex flex-col items-center gap-3 rounded-icon border-[1.5px] border-dashed bg-surface px-4 py-5 text-center',
                    error ? 'border-status-failure' : dragging ? 'border-fg' : 'border-line-strong',
                )}
            >
                <span className="text-sm font-semibold">
                    <span className="hidden md:inline">Arrastra aquí o elige</span>
                    <span className="md:hidden">Elige cómo adjuntar</span>
                </span>
                <div className="flex flex-wrap justify-center gap-2">
                    <Button variant="secondary" icon="image" onClick={() => cameraRef.current?.click()}>
                        Tomar foto
                    </Button>
                    <Button variant="secondary" icon="upload" onClick={() => fileRef.current?.click()}>
                        Subir archivo
                    </Button>
                </div>
                <span className="text-xs text-fg-muted">Imágenes, PDF o Excel · hasta {maxMb} MB cada uno</span>
                <input
                    ref={cameraRef}
                    type="file"
                    accept="image/*"
                    capture="environment"
                    tabIndex={-1}
                    aria-hidden="true"
                    className="sr-only"
                    onChange={(event) => {
                        add(event.target.files);
                        event.target.value = '';
                    }}
                />
                <input
                    ref={fileRef}
                    type="file"
                    multiple
                    accept={ACCEPT}
                    tabIndex={-1}
                    aria-hidden="true"
                    className="sr-only"
                    onChange={(event) => {
                        add(event.target.files);
                        event.target.value = '';
                    }}
                />
            </div>
            {error ? (
                <p role="alert" className="flex items-start gap-1.5 text-[13px] font-semibold text-status-failure">
                    <span className="mt-0.5">
                        <Icon name="alert-triangle" size={14} />
                    </span>
                    {error}
                </p>
            ) : null}
            {files.length > 0 ? (
                <ul aria-label="Archivos adjuntos" className="flex flex-col gap-2">
                    {files.map((file, index) => (
                        <li key={`${file.name}-${index}`} className="flex items-center gap-3 rounded-control border border-line bg-surface py-1.5 pr-1.5 pl-2.5">
                            <span
                                aria-hidden="true"
                                className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-subtle text-[10px] font-bold text-fg-soft"
                            >
                                {file.type.startsWith('image/') ? <Icon name="image" size={16} /> : extension(file.name)}
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col">
                                <span className="truncate text-sm font-semibold">{file.name}</span>
                                <span className="tabular text-xs text-fg-muted">{formatFileSize(file.size)}</span>
                            </span>
                            <button
                                type="button"
                                aria-label={`Quitar ${file.name}`}
                                onClick={() => onChange(files.filter((_, position) => position !== index))}
                                className="flex size-11 shrink-0 items-center justify-center rounded-control text-fg-soft hover:bg-subtle"
                            >
                                <Icon name="x" size={18} />
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

// ---- review -----------------------------------------------------------------

export function ReviewRow({ label, onEdit, invalid, children }: { label: string; onEdit: () => void; invalid?: boolean; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1 border-t border-line px-4 py-3 first:border-t-0">
            <div className="flex items-center gap-2">
                <dt className="text-[13px] font-semibold text-fg-muted">{label}</dt>
                {invalid ? (
                    <span className="flex items-center gap-1 text-xs font-bold text-status-failure">
                        <Icon name="alert-triangle" size={12} />
                        Revisa este paso
                    </span>
                ) : null}
                <button
                    type="button"
                    onClick={onEdit}
                    aria-label={`Editar: ${label}`}
                    className="-my-2 ml-auto flex min-h-11 items-center px-1 text-[13px] font-bold underline underline-offset-2"
                >
                    Editar
                </button>
            </div>
            <dd className="text-sm leading-snug">{children}</dd>
        </div>
    );
}
