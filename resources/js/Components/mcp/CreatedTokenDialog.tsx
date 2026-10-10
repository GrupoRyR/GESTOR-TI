import { useId, useState } from 'react';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { formatDate, formatTime } from '@/lib/format';
import { calendarIso, maskToken } from '@/lib/mcp';
import type { McpCreatedToken } from '@/types/mcp';
import { McpModal } from './McpModal';
import { CopyStatus, ScopeChip, SectionTile, useCopy } from './parts';

export interface CreatedTokenDialogProps {
    token: McpCreatedToken | null;
    /** "Ya lo guardé" / "Agregarlo en Claude": the page drops the value from memory and from the Inertia page. */
    onDone: (next?: 'setup') => void;
    /** Gallery only: start in the "Copiado" state. */
    initiallyCopied?: boolean;
}

/**
 * E-McpCreated: the only time the full value is visible. It cannot be
 * dismissed by Esc or the backdrop; only an explicit action closes it.
 * Never log `token.value`.
 */
export function CreatedTokenDialog({ token, onDone, initiallyCopied = false }: CreatedTokenDialogProps) {
    const { state, copy } = useCopy(null);
    const [copiedAt, setCopiedAt] = useState<Date | null>(initiallyCopied ? new Date() : null);
    const valueId = useId();
    const copied = state === 'copied' || (initiallyCopied && state === 'idle');

    if (!token) return null;

    const doCopy = async () => {
        await copy(token.value);
        setCopiedAt(new Date());
    };

    return (
        <McpModal
            open
            dismissible={false}
            onClose={() => onDone()}
            leading={<SectionTile icon="check-circle" tone="green" />}
            title={`Token creado: ${token.name}`}
            description={
                <span className="flex flex-wrap items-center gap-2">
                    <ScopeChip scope={token.scope} />
                    <span>Vence el {formatDate(calendarIso(token.expires_at))}</span>
                </span>
            }
            footnote="El valor no queda en el registro ni en la dirección de la página."
            actions={
                <Button variant="dark" onClick={() => onDone()}>
                    Ya lo guardé
                </Button>
            }
        >
            <div className="flex flex-col gap-1.5">
                <label htmlFor={valueId} className="text-[13px] font-semibold">
                    Tu token
                </label>
                <div className="flex gap-2 max-sm:flex-col">
                    <input
                        id={valueId}
                        type="text"
                        readOnly
                        value={token.value}
                        autoComplete="off"
                        spellCheck={false}
                        onFocus={(event) => event.currentTarget.select()}
                        className="h-12 min-w-0 flex-1 rounded-control border border-line-strong bg-ground px-3.5 font-mono text-[14px] font-semibold text-fg outline-none select-all focus-visible:outline-2 focus-visible:outline-fg"
                    />
                    <Button variant={copied ? 'dark' : 'primary'} size="lg" icon={copied ? 'check' : 'copy'} onClick={doCopy}>
                        {copied ? 'Copiado' : 'Copiar'}
                    </Button>
                </div>
                <span className="text-xs text-fg-muted">
                    {state === 'failed'
                        ? 'El navegador no dejó copiar. Selecciona el valor y cópialo a mano.'
                        : copiedAt
                          ? `Copiado al portapapeles hoy ${formatTime(copiedAt.toISOString())}`
                          : 'Cópialo y pégalo en Claude o en tu gestor de contraseñas.'}
                </span>
                <CopyStatus state={state} />
            </div>

            <div role="alert" className="flex items-start gap-2.5 rounded-[12px] bg-status-maintenance-bg px-3.5 py-3 text-[13px] leading-[1.45] text-status-maintenance">
                <Icon name="alert-triangle" size={18} className="mt-px shrink-0" />
                <span className="flex flex-col gap-1">
                    <b className="text-sm">Guárdalo ahora. No lo vas a poder ver otra vez.</b>
                    <span className="text-fg-soft">
                        Al cerrar esta ventana solo verás sus últimos 4 caracteres ({maskToken(token.value)}). Si lo pierdes, revócalo y crea otro. No lo pegues en
                        correos, chats ni documentos compartidos.
                    </span>
                </span>
            </div>

            <div className="flex flex-wrap items-center gap-3 rounded-[12px] border border-line px-3.5 py-3">
                <SectionTile icon="book" size={36} />
                <span className="flex min-w-[12rem] flex-1 basis-[12rem] flex-col">
                    <b className="text-sm">Siguiente paso</b>
                    <span className="text-[13px] text-fg-soft">Pega la dirección del servidor y este token en Claude.</span>
                </span>
                <Button variant="secondary" icon="chevron-right" iconPosition="end" onClick={() => onDone('setup')}>
                    Agregarlo en Claude
                </Button>
            </div>
        </McpModal>
    );
}
