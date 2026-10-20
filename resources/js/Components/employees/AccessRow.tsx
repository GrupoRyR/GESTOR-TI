import { useEffect, useRef, useState } from 'react';
import { CLIPBOARD_SECONDS } from '@/Components/ai/vault/vault';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { formatDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { EmployeeAccess } from '@/types/pages/admin-employees';
import { useAccessReveal } from './useAccessReveal';

type CopyState = 'idle' | 'copied' | 'failed';

/** "Usuario: jperez · contraseña cambiada el 7 oct 2026" (username only with `credentials.view`). */
function accessLine(access: EmployeeAccess): string {
    const rotated = access.last_rotated_at ? `contraseña cambiada el ${formatDate(access.last_rotated_at)}` : 'sin fecha de cambio de contraseña';
    if (access.username) return `Usuario: ${access.username} · ${rotated}`;
    return rotated.charAt(0).toUpperCase() + rotated.slice(1);
}

function SystemTile() {
    return (
        <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-subtle text-fg-soft">
            <Icon name="server" size={16} />
        </span>
    );
}

export interface AccessRowProps {
    employeeId: number;
    access: EmployeeAccess;
    /** `credentials.view`: shows "Ver contraseña". */
    canReveal: boolean;
}

/**
 * One access of the employee page (A-EmployeeDetail). With `canReveal`,
 * "Ver contraseña" asks the server for the value and shows it 30 s with a
 * bar, "Copiar" and "Ocultar"; then it hides on its own.
 */
export function AccessRow({ employeeId, access, canReveal }: AccessRowProps) {
    const system = access.system.name;
    const { state, reveal, hide } = useAccessReveal(route('employees.accesses.reveal', { employee: employeeId, access: access.id }));
    const [copy, setCopy] = useState<CopyState>('idle');
    const [announcement, setAnnouncement] = useState('');
    const wasShown = useRef(false);
    const revealButton = useRef<HTMLButtonElement>(null);
    const row = useRef<HTMLLIElement>(null);
    const shown = state.phase === 'shown' && state.value !== null;

    // Polite announcements only at the edges (shown, hidden), never every second.
    useEffect(() => {
        if (shown && !wasShown.current) {
            setAnnouncement(`Contraseña de ${system} visible. Se oculta en ${state.total} segundos.`);
            setCopy('idle');
        } else if (!shown && wasShown.current) {
            setAnnouncement(`La contraseña de ${system} se ocultó.`);
            // Keep keyboard users in place: "Ocultar" disappears with the value.
            const active = document.activeElement;
            if (active === null || active === document.body || row.current?.contains(active)) revealButton.current?.focus();
        }
        wasShown.current = shown;
    }, [shown, system, state.total]);

    useEffect(() => {
        if (state.phase === 'error' && state.error) setAnnouncement(state.error);
    }, [state.phase, state.error]);

    const copyValue = async () => {
        if (state.value === null) return;
        try {
            await navigator.clipboard.writeText(state.value);
            setCopy('copied');
            setAnnouncement(`Contraseña copiada. El portapapeles se limpia en ${CLIPBOARD_SECONDS} segundos.`);
            // Best effort: browsers may refuse to write the clipboard when the tab is not focused.
            window.setTimeout(() => {
                navigator.clipboard.writeText('').catch(() => undefined);
            }, CLIPBOARD_SECONDS * 1000);
        } catch {
            setCopy('failed');
            setAnnouncement('No se pudo copiar. Selecciona el valor y cópialo a mano.');
        }
    };

    return (
        <li ref={row} className={cx('flex flex-col gap-2.5 border-t border-line px-4 py-3 md:px-5', shown && 'bg-ground/60')}>
            <div className="grid grid-cols-[36px_minmax(0,1fr)] items-center gap-x-3.5 gap-y-2 sm:grid-cols-[36px_minmax(0,1fr)_auto]">
                <SystemTile />
                <span className="flex min-w-0 flex-col gap-0.5">
                    <b className="text-[15px] font-semibold md:text-sm">{system}</b>
                    <span className="text-[13px] [overflow-wrap:anywhere] text-fg-soft">{accessLine(access)}</span>
                    {access.notes ? <span className="text-[13px] [overflow-wrap:anywhere] text-fg-muted">{access.notes}</span> : null}
                </span>
                {canReveal ? (
                    shown ? (
                        <span className="tabular col-start-2 text-[13px] font-semibold text-fg-soft sm:col-start-auto">Se oculta en {state.secondsLeft} s</span>
                    ) : (
                        <button
                            ref={revealButton}
                            type="button"
                            onClick={() => void reveal()}
                            disabled={state.phase === 'loading'}
                            aria-label={`Ver la contraseña de ${system}`}
                            className="col-start-2 inline-flex h-11 items-center justify-center gap-1.5 justify-self-start rounded-control border border-line-strong bg-surface px-3.5 text-sm font-bold text-fg hover:bg-subtle disabled:opacity-60 sm:col-start-auto"
                        >
                            <Icon name="eye" size={16} />
                            {state.phase === 'loading' ? 'Abriendo…' : 'Ver contraseña'}
                        </button>
                    )
                ) : null}
            </div>

            {state.phase === 'error' && state.error ? (
                <p className="flex items-start gap-1.5 text-[13px] text-fg-soft sm:ml-[50px]">
                    <Icon name="info" size={14} className="mt-0.5 shrink-0 text-status-maintenance" />
                    {state.error}
                </p>
            ) : null}

            {shown ? (
                <div className="flex flex-col gap-2 sm:ml-[50px]">
                    <span
                        aria-label={`Contraseña de ${system}`}
                        className="flex min-h-11 items-center rounded-[10px] bg-subtle px-3.5 py-2 font-mono text-[17px] tracking-[0.04em] break-all select-all"
                    >
                        {state.value}
                    </span>
                    <div aria-hidden="true" className="h-1 overflow-hidden rounded-full bg-line-strong">
                        <div
                            className="h-full bg-fg transition-[width] duration-1000 ease-linear motion-reduce:transition-none"
                            style={{ width: `${(state.secondsLeft / state.total) * 100}%` }}
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="flex min-w-0 flex-1 basis-full items-center gap-1.5 text-xs text-fg-muted sm:basis-auto">
                            <Icon name="shield" size={14} />
                            Queda registrado en la auditoría
                        </span>
                        <button
                            type="button"
                            onClick={() => void copyValue()}
                            aria-label={`Copiar la contraseña de ${system}`}
                            className="inline-flex h-11 items-center gap-1.5 rounded-control bg-fg px-3.5 text-sm font-bold text-on-fg hover:brightness-[0.9]"
                        >
                            <Icon name={copy === 'copied' ? 'check' : 'copy'} size={16} />
                            {copy === 'copied' ? 'Copiada' : 'Copiar'}
                        </button>
                        <button
                            type="button"
                            onClick={hide}
                            aria-label={`Ocultar la contraseña de ${system}`}
                            className="inline-flex h-11 items-center gap-1.5 rounded-control border border-line-strong bg-surface px-3 text-sm font-bold text-fg hover:bg-subtle"
                        >
                            <Icon name="eye-off" size={16} />
                            Ocultar
                        </button>
                    </div>
                    {copy === 'failed' ? <p className="text-[13px] font-semibold text-status-failure">No se pudo copiar. Selecciona el valor y cópialo a mano.</p> : null}
                </div>
            ) : null}

            <span aria-live="polite" className="sr-only">
                {announcement}
            </span>
        </li>
    );
}
