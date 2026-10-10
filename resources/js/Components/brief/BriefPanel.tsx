import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { Button } from '@/Components/ui/Button';
import { Drawer } from '@/Components/ui/Drawer';
import { Icon } from '@/Components/ui/Icon';
import { MarkdownPreview } from '@/Components/ui/MarkdownPreview';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { cx } from '@/Components/ui/utils';
import { ALL_BRIEF_SECTIONS, BRIEF_SECTIONS, DEFAULT_BRIEF_SECTIONS, briefStats, type BriefSectionKey } from '@/lib/brief';

export type BriefView = 'preview' | 'markdown';
export type BriefTab = 'brief' | 'claude';

export interface BriefClaudeMd {
    markdown: string;
    /** "CLAUDE.md". */
    fileName: string;
    /** Extra content under the preview. */
    children?: ReactNode;
    /**
     * Replaces the footer on this tab (D-BriefProject: repo name field,
     * "Copiar" and "Crear repositorio con este CLAUDE.md"). Gets the copy state.
     */
    renderFooter?: (copy: BriefCopyControl) => ReactNode;
}

export interface BriefCopyControl {
    copied: boolean;
    onCopy: () => void;
}

export interface BriefPanelProps {
    open: boolean;
    onClose: () => void;
    /** "GIA-139 · Visualizador de cartera: filtro por asesor y zona". */
    subject: string;
    /** Builds the Markdown for the enabled sections (a lib/brief builder). */
    build: (sections: BriefSectionKey[]) => string;
    /** Sections on when the panel opens (default 4 de 6). */
    defaultSections?: BriefSectionKey[];
    /** Sections this entity can offer; the rest render disabled. Default: all six. */
    availableSections?: BriefSectionKey[];
    /** Download name, e.g. "GIA-139-brief.md". */
    fileName: string;
    /** Adds the "Brief del proyecto / CLAUDE.md inicial" tabs. */
    claudeMd?: BriefClaudeMd | null;
    /** "Cómo trabajar con Claude" strip (projects). */
    showWorkflow?: boolean;
    /** "Aquí se omitieron el tiempo y costos y 2 notas internas." */
    omittedNote?: string | null;
    /** Announced after copying. */
    copiedMessage?: string;
    initialView?: BriefView;
    initialTab?: BriefTab;
    /** Gallery only: start in the "Copiado" state. */
    initiallyCopied?: boolean;
    width?: number;
}

const COPIED_MS = 4000;

function approxWords(words: number): string {
    const rounded = words < 100 ? words : Math.round(words / 10) * 10;
    return `≈ ${rounded.toLocaleString('es-CO')} palabras`;
}

async function copyText(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        // Fallback for browsers that refuse the async clipboard (e.g. not focused).
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        return ok;
    }
}

function downloadMarkdown(markdown: string, fileName: string) {
    const blob = new Blob([markdown], { type: 'text/markdown;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 0);
}

/** Raw Markdown with line numbers (numbers are decorative). */
export function MarkdownLines({ markdown, label }: { markdown: string; label: string }) {
    const lines = markdown.replace(/\n$/, '').split('\n');
    return (
        <div role="region" aria-label={label} tabIndex={0} className="max-h-[60vh] overflow-auto rounded-control border border-line bg-surface py-2 font-mono text-xs leading-[1.7] md:max-h-none">
            {lines.map((line, index) => (
                <div key={index} className="grid grid-cols-[2.75rem_minmax(0,1fr)]">
                    <span aria-hidden="true" className="tabular pr-3 text-right text-fg-muted select-none">
                        {index + 1}
                    </span>
                    <code className={cx('pr-3 whitespace-pre', /^#{1,6}\s/.test(line) ? 'font-bold text-fg' : 'text-fg-soft')}>{line === '' ? ' ' : line}</code>
                </div>
            ))}
        </div>
    );
}

function WorkflowStrip() {
    const steps = [
        { title: 'Brief', note: 'hoy', on: true },
        { title: 'GitHub', note: 'issues y PR', on: true },
        { title: 'Conector MCP', note: 'etapa E · aún no', on: false },
    ];
    return (
        <section aria-label="Cómo trabajar con Claude" className="flex flex-col gap-2">
            <h3 className="text-[13px] font-bold text-fg">Cómo trabajar con Claude</h3>
            <ol className="grid grid-cols-3 gap-2">
                {steps.map((step, index) => (
                    <li key={step.title} className={cx('flex flex-col gap-0.5 rounded-control border px-3 py-2', step.on ? 'border-line bg-surface' : 'border-dashed border-line-strong text-fg-muted')}>
                        <span className="tabular text-xs font-bold text-fg-muted">{index + 1}</span>
                        <span className={cx('text-[13px] font-bold', step.on ? 'text-fg' : 'text-fg-muted')}>{step.title}</span>
                        <span className="text-xs text-fg-muted">{step.note}</span>
                    </li>
                ))}
            </ol>
        </section>
    );
}

/**
 * "Brief para Claude": drawer (~560 px) on desktop, full-screen sheet on
 * phone. Built from the app template, it never calls the AI and never
 * includes vault, credentials, costs, rates or internal notes.
 */
export function BriefPanel({
    open,
    onClose,
    subject,
    build,
    defaultSections = DEFAULT_BRIEF_SECTIONS,
    availableSections = ALL_BRIEF_SECTIONS,
    fileName,
    claudeMd = null,
    showWorkflow = false,
    omittedNote = null,
    copiedMessage = 'Brief copiado. Pégalo en Claude.',
    initialView = 'preview',
    initialTab = 'brief',
    initiallyCopied = false,
    width = 560,
}: BriefPanelProps) {
    const [sections, setSections] = useState<BriefSectionKey[]>(defaultSections);
    const [view, setView] = useState<BriefView>(initialView);
    const [tab, setTab] = useState<BriefTab>(claudeMd ? initialTab : 'brief');
    const [copied, setCopied] = useState(initiallyCopied);
    const timer = useRef<number | null>(null);
    const tabsId = useId();

    useEffect(() => () => {
        if (timer.current !== null) window.clearTimeout(timer.current);
    }, []);

    const briefMarkdown = useMemo(() => build(sections), [build, sections]);
    const onClaude = tab === 'claude' && claudeMd !== null;
    const markdown = onClaude ? claudeMd.markdown : briefMarkdown;
    const stats = briefStats(markdown);

    function toggle(key: BriefSectionKey) {
        setSections((current) => (current.includes(key) ? current.filter((item) => item !== key) : BRIEF_SECTIONS.map((s) => s.key).filter((k) => k === key || current.includes(k))));
    }

    async function copy() {
        const ok = await copyText(markdown);
        if (!ok) return;
        setCopied(true);
        if (timer.current !== null) window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setCopied(false), COPIED_MS);
    }

    const enabledCount = sections.filter((key) => availableSections.includes(key)).length;

    const copiedStatus = (
        <p
            aria-live="polite"
            role="status"
            className={cx('flex min-h-11 items-center gap-2.5 rounded-control bg-fg px-3.5 text-sm font-semibold text-on-fg', !copied && 'sr-only')}
        >
            {copied ? (
                <>
                    <Icon name="check" size={18} className="shrink-0 text-status-active-dot" />
                    {onClaude ? 'CLAUDE.md copiado.' : copiedMessage}
                </>
            ) : (
                ''
            )}
        </p>
    );

    const defaultFooter = (
        <div className="flex w-full flex-col gap-2.5">
            <div className="flex flex-col gap-1.5 text-[13px] leading-snug text-fg-soft">
                <span className="flex items-start gap-2">
                    <Icon name="shield" size={16} className="mt-px shrink-0" />
                    <span>
                        <b className="font-semibold text-fg">No incluye contraseñas, bóveda, costos ni notas internas.</b>
                        {omittedNote && !onClaude ? ` ${omittedNote}` : ''}
                    </span>
                </span>
                <span className="pl-6">
                    <b className="font-semibold text-fg">Cómo usarlo:</b> pégalo en un proyecto de Claude o en Claude Code.
                </span>
            </div>
            {copiedStatus}
            <div className="flex gap-2">
                <Button
                    variant="secondary"
                    size="lg"
                    icon="download"
                    aria-label="Descargar .md"
                    onClick={() => downloadMarkdown(markdown, onClaude ? claudeMd.fileName : fileName)}
                    className="w-12 px-0 sm:w-auto sm:px-4"
                >
                    <span className="hidden sm:inline">Descargar .md</span>
                </Button>
                <Button variant={copied ? 'dark' : 'primary'} size="lg" icon={copied ? 'check' : 'copy'} onClick={copy} className="flex-1">
                    {copied ? 'Copiado' : onClaude ? 'Copiar' : 'Copiar brief'}
                </Button>
            </div>
        </div>
    );

    return (
        <Drawer
            open={open}
            onClose={onClose}
            title="Brief para Claude"
            width={width}
            footer={
                onClaude && claudeMd.renderFooter ? (
                    <div className="flex w-full flex-col gap-2.5">
                        {copiedStatus}
                        {claudeMd.renderFooter({ copied, onCopy: copy })}
                    </div>
                ) : (
                    defaultFooter
                )
            }
        >
            <div className="flex flex-col gap-2">
                <p className="text-[15px] font-bold text-fg">{subject}</p>
                <span className="inline-flex h-[26px] items-center gap-1.5 self-start rounded-chip bg-subtle px-[9px] text-[13px] font-semibold text-fg-soft">
                    <Icon name="list" size={13} />
                    Armado con la plantilla de la app · no usa IA
                </span>
            </div>

            {claudeMd ? (
                <div role="tablist" aria-label="Contenido del brief" className="flex gap-1 border-b border-line">
                    {(
                        [
                            ['brief', 'Brief del proyecto'],
                            ['claude', 'CLAUDE.md inicial'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            id={`${tabsId}-${key}`}
                            aria-selected={tab === key}
                            aria-controls={`${tabsId}-panel`}
                            onClick={() => setTab(key)}
                            className={cx(
                                'h-11 border-b-[3px] px-3 text-sm whitespace-nowrap',
                                tab === key ? 'border-fg font-bold text-fg' : 'border-transparent font-semibold text-fg-soft hover:text-fg',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            ) : null}

            <div id={`${tabsId}-panel`} role={claudeMd ? 'tabpanel' : undefined} aria-labelledby={claudeMd ? `${tabsId}-${tab}` : undefined} className="flex flex-col gap-4">
                {showWorkflow && !onClaude ? <WorkflowStrip /> : null}

                {onClaude ? (
                    <p className="text-[13px] text-fg-soft">Lo que Claude lee al abrir el repositorio. Se llena con los datos del proyecto.</p>
                ) : (
                    <fieldset className="flex min-w-0 flex-col gap-2">
                        <legend className="mb-2 flex w-full items-baseline justify-between gap-2 text-[13px]">
                            <b className="text-fg">Qué incluye</b>
                            <span className="tabular text-fg-muted">
                                {enabledCount} de {availableSections.length} secciones
                                <span className="md:hidden"> · desliza para ver más</span>
                            </span>
                        </legend>
                        <div className="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] md:mx-0 md:flex-wrap md:px-0">
                            {BRIEF_SECTIONS.map((section) => {
                                const available = availableSections.includes(section.key);
                                const on = available && sections.includes(section.key);
                                return (
                                    <button
                                        key={section.key}
                                        type="button"
                                        aria-pressed={on}
                                        disabled={!available}
                                        onClick={() => toggle(section.key)}
                                        className={cx(
                                            'inline-flex h-11 shrink-0 items-center gap-1.5 rounded-control border px-3 text-[13px] whitespace-nowrap transition-colors duration-150',
                                            on ? 'border-fg bg-fg font-bold text-on-fg' : 'border-line-strong bg-surface font-semibold text-fg-soft hover:text-fg',
                                            !available && 'pointer-events-none opacity-50',
                                        )}
                                    >
                                        <Icon name={on ? 'check' : 'plus'} size={14} />
                                        {section.label}
                                    </button>
                                );
                            })}
                        </div>
                    </fieldset>
                )}

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <SegmentedControl
                        label="Cómo ver el brief"
                        value={view}
                        onChange={setView}
                        options={[
                            { value: 'preview', label: 'Vista previa' },
                            { value: 'markdown', label: 'Markdown' },
                        ]}
                    />
                    <span className="tabular text-[13px] text-fg-muted">
                        {approxWords(stats.words)} · {stats.lines} {stats.lines === 1 ? 'línea' : 'líneas'}
                    </span>
                </div>

                {view === 'preview' ? (
                    <div className="rounded-control border border-line bg-surface px-4 py-3">
                        <MarkdownPreview source={markdown} emptyText="Activa al menos una sección para armar el brief." className="text-sm" />
                    </div>
                ) : (
                    <MarkdownLines markdown={markdown} label={onClaude ? 'Markdown del CLAUDE.md' : 'Markdown del brief'} />
                )}

                {onClaude ? claudeMd.children : null}

            </div>

        </Drawer>
    );
}
