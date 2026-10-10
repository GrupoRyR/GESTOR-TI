import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Button, type ButtonVariant } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { bogotaDateKey, formatDate, formatTime } from '@/lib/format';
import { daysLeft, MCP_SCOPE_LABELS } from '@/lib/mcp';
import type { McpCall, McpScope, McpTool } from '@/types/mcp';

export type CopyState = 'idle' | 'copied' | 'failed';

/** Clipboard copy with a short-lived "Copiado" state (`resetMs: null` keeps it). */
export function useCopy(resetMs: number | null = 2500): { state: CopyState; copy: (text: string) => Promise<void> } {
    const [state, setState] = useState<CopyState>('idle');
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const copy = async (text: string) => {
        try {
            await navigator.clipboard.writeText(text);
            setState('copied');
        } catch {
            setState('failed');
        }
        window.clearTimeout(timer.current);
        if (resetMs !== null) timer.current = window.setTimeout(() => setState('idle'), resetMs);
    };

    return { state, copy };
}

/** Polite announcement for a copy action ("Copiado"). */
export function CopyStatus({ state, failedText = 'El navegador no dejó copiar. Selecciona el texto y cópialo a mano.' }: { state: CopyState; failedText?: string }) {
    return (
        <span role="status" className="sr-only">
            {state === 'copied' ? 'Copiado' : state === 'failed' ? failedText : ''}
        </span>
    );
}

export interface CopyButtonProps {
    text: string;
    label?: string;
    /** Accessible name when the visible label is ambiguous ("Copiar comando para…"). */
    ariaLabel?: string;
    variant?: ButtonVariant;
    iconOnly?: boolean;
    disabled?: boolean;
    className?: string;
}

export function CopyButton({ text, label = 'Copiar', ariaLabel, variant = 'secondary', iconOnly = false, disabled, className }: CopyButtonProps) {
    const { state, copy } = useCopy();
    const copied = state === 'copied';
    return (
        <>
            {iconOnly ? (
                <button
                    type="button"
                    onClick={() => copy(text)}
                    aria-label={ariaLabel ?? label}
                    title={ariaLabel ?? label}
                    disabled={disabled}
                    className={cx(
                        'inline-flex size-11 shrink-0 items-center justify-center rounded-control text-fg-soft transition-colors hover:bg-subtle hover:text-fg disabled:opacity-50',
                        className,
                    )}
                >
                    <Icon name={copied ? 'check' : 'copy'} size={16} />
                </button>
            ) : (
                <Button variant={variant} icon={copied ? 'check' : 'copy'} onClick={() => copy(text)} aria-label={ariaLabel} disabled={disabled} className={className}>
                    {copied ? 'Copiado' : label}
                </Button>
            )}
            <CopyStatus state={state} />
        </>
    );
}

/** Read-only monospace value with a Copiar button (server URL, commands). */
export function CopyField({ id, label, value, copyLabel, ariaCopyLabel }: { id: string; label: ReactNode; value: string; copyLabel?: string; ariaCopyLabel?: string }) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <label htmlFor={id} className="text-[13px] font-semibold">
                {label}
            </label>
            <div className="flex min-w-0 gap-2 max-sm:flex-col">
                <input
                    id={id}
                    type="text"
                    readOnly
                    value={value}
                    onFocus={(event) => event.currentTarget.select()}
                    className="h-11 min-w-0 flex-1 rounded-control border border-line-strong bg-ground px-3.5 font-mono text-[13px] text-fg outline-none focus-visible:outline-2 focus-visible:outline-fg"
                />
                <CopyButton text={value} label={copyLabel} ariaLabel={ariaCopyLabel} />
            </div>
        </div>
    );
}

export function ScopeChip({ scope, size = 'sm' }: { scope: McpScope; size?: 'sm' | 'md' }) {
    return (
        <Chip tone={scope === 'read' ? 'slate' : 'amber'} icon={scope === 'read' ? 'eye' : 'edit'} size={size}>
            {MCP_SCOPE_LABELS[scope]}
        </Chip>
    );
}

/** 30 px tile: slate eye for read tools, amber pencil for write tools. */
export function ToolTile({ access, size = 30 }: { access: McpTool['access']; size?: number }) {
    return (
        <span
            aria-hidden="true"
            style={{ width: size, height: size }}
            className={cx(
                'flex shrink-0 items-center justify-center rounded-[10px]',
                access === 'read' ? 'bg-status-local-bg text-status-local' : 'bg-status-maintenance-bg text-status-maintenance',
            )}
        >
            <Icon name={access === 'read' ? 'eye' : 'edit'} size={Math.round(size / 2)} />
        </span>
    );
}

export function SectionTile({ icon, tone = 'neutral', size = 44 }: { icon: IconName; tone?: 'neutral' | 'brand' | 'amber' | 'red' | 'green'; size?: number }) {
    return (
        <span
            aria-hidden="true"
            style={{ width: size, height: size }}
            className={cx(
                'flex shrink-0 items-center justify-center rounded-icon',
                tone === 'neutral' && 'bg-subtle text-fg-soft',
                tone === 'brand' && 'bg-brand-tint text-brand-ink',
                tone === 'amber' && 'bg-status-maintenance-bg text-status-maintenance',
                tone === 'red' && 'bg-status-failure-bg text-status-failure',
                tone === 'green' && 'bg-status-active-bg text-status-active',
            )}
        >
            <Icon name={icon} size={Math.round(size * 0.45)} />
        </span>
    );
}

/** Icon of a token: terminal for Claude Code, chat bubble otherwise (cosmetic, from its name). */
export function tokenIcon(name: string): IconName {
    return /claude code/i.test(name) ? 'terminal' : 'chat';
}

/** "Hoy 9:12 a. m." / "Ayer 5:38 p. m." for the last two days, else "8 oct 2026". */
export function whenText(iso: string, now: Date): string {
    const days = daysLeft(bogotaDateKey(now.toISOString()), new Date(iso));
    if (days === 0) return `Hoy ${formatTime(iso)}`;
    if (days === 1) return `Ayer ${formatTime(iso)}`;
    return formatDate(iso);
}

const RESULT: Record<McpCall['result'], { tone: 'green' | 'red'; icon: IconName; label: string }> = {
    ok: { tone: 'green', icon: 'check', label: 'Correcta' },
    rejected: { tone: 'red', icon: 'blocked', label: 'Rechazada' },
    error: { tone: 'red', icon: 'x-circle', label: 'Error' },
};

export function ResultChip({ call }: { call: Pick<McpCall, 'result' | 'reason'> }) {
    const result = RESULT[call.result];
    return (
        <Chip tone={result.tone} icon={result.icon} size="sm" className="max-w-full">
            <span className="truncate">
                {result.label}
                {call.reason ? ` · ${call.reason}` : ''}
            </span>
        </Chip>
    );
}

export const SETUP_ANCHOR = 'como-agregarlo';
export const ACTIVITY_ANCHOR = 'actividad';

export function scrollToAnchor(id: string) {
    const target = document.getElementById(id);
    if (!target) return;
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    target.focus({ preventScroll: true });
}
