import { useEffect, useRef, useState } from 'react';
import { CountdownRing } from '@/Components/ui/CountdownRing';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { CLIPBOARD_SECONDS } from '../ai/vault/vault';
import type { CredentialNoticeMessage, RevealedCredential } from '@/types/assistant';
import { messageTime } from '@/lib/chat';
import { useRevealedCredential } from './useRevealedCredential';

export interface CredentialCardProps {
    notice: CredentialNoticeMessage;
    /** The page's `revealed_credential` prop; only used when it belongs to this card. */
    revealed: RevealedCredential | null;
    /** Ask the server for the value (`router.post`, audited). */
    onReveal: () => void;
    /** While the reveal request is in flight. */
    revealing?: boolean;
    /** Tell the server the value was copied (audit). */
    onCopied?: () => void;
    className?: string;
}

type CopyState = 'idle' | 'copied' | 'failed';

/**
 * Password delivered by the Gestor TI in its own card, never by the AI.
 * Hidden by default; "Mostrar" brings the value for 30 s with a ring, a bar
 * and "Copiar" / "Ocultar"; at 0 it hides again on its own. Always dark
 * (`vault-zone` tokens) in both themes.
 */
export function CredentialCard({ notice, revealed, onReveal, revealing = false, onCopied, className }: CredentialCardProps) {
    const { value, secondsLeft, hide } = useRevealedCredential(revealed, notice.id);
    const [copy, setCopy] = useState<CopyState>('idle');
    const [announcement, setAnnouncement] = useState('');
    const wasShown = useRef(false);
    const total = Math.max(1, notice.reveal_seconds);
    const { person } = notice;

    // Polite announcements only at the edges (shown, hidden), never every second.
    useEffect(() => {
        if (value !== null && !wasShown.current) {
            setAnnouncement(`Contraseña visible. Se oculta en ${total} segundos.`);
            setCopy('idle');
        } else if (value === null && wasShown.current) {
            setAnnouncement('La contraseña se ocultó.');
        }
        wasShown.current = value !== null;
    }, [value, total]);

    const copyValue = async () => {
        if (value === null) return;
        try {
            await navigator.clipboard.writeText(value);
            setCopy('copied');
            setAnnouncement('Contraseña copiada. El portapapeles se limpia en 30 segundos.');
            onCopied?.();
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
        <article
            aria-label="Contraseña entregada por el Gestor TI"
            className={cx('vault-zone flex w-full flex-col gap-2.5 self-start rounded-[16px] border border-line p-3.5 text-fg md:max-w-[560px] md:p-4', className)}
        >
            <div className="flex items-start gap-3">
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="text-xs font-semibold text-fg-muted">Contraseña · sistema {notice.system}</span>
                    <b className="text-[15px]">
                        {notice.system} · {person.name} ({person.area}, {person.company_name})
                    </b>
                    <span className="text-[13px] [overflow-wrap:anywhere] text-fg-soft">Usuario: {notice.username}</span>
                </div>
                {value !== null ? <CountdownRing seconds={secondsLeft} total={total} label="La contraseña se oculta en" size={52} /> : null}
            </div>

            {value !== null ? (
                <>
                    <span className="flex min-h-11 items-center rounded-[10px] bg-subtle px-3.5 py-2 font-mono text-[17px] tracking-[0.04em] break-all select-all">{value}</span>
                    <div aria-hidden="true" className="h-1 overflow-hidden rounded-full bg-line-strong">
                        <div className="h-full bg-fg transition-[width] duration-1000 ease-linear" style={{ width: `${(secondsLeft / total) * 100}%` }} />
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="tabular mr-auto text-[13px] font-semibold text-fg-soft">Se oculta en {secondsLeft} s</span>
                        <button
                            type="button"
                            onClick={copyValue}
                            className="inline-flex h-11 items-center gap-1.5 rounded-control bg-fg px-3.5 text-sm font-bold text-on-fg hover:brightness-[0.9]"
                        >
                            <Icon name={copy === 'copied' ? 'check' : 'copy'} size={16} />
                            {copy === 'copied' ? 'Copiada' : 'Copiar'}
                        </button>
                        <button
                            type="button"
                            onClick={hide}
                            className="inline-flex h-11 items-center gap-1.5 rounded-control border border-line-strong px-3 text-sm font-bold text-fg hover:bg-subtle"
                        >
                            <Icon name="eye-off" size={16} />
                            Ocultar
                        </button>
                    </div>
                    {copy === 'failed' ? (
                        <p className="text-[13px] font-semibold text-status-failure">No se pudo copiar. Selecciona el valor y cópialo a mano.</p>
                    ) : null}
                </>
            ) : (
                <>
                    <span aria-label="Contraseña oculta" className="flex min-h-11 items-center rounded-[10px] bg-subtle px-3.5 font-mono text-[17px] tracking-[0.2em] text-fg-muted">
                        <span aria-hidden="true">••••••••••••</span>
                    </span>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="mr-auto text-[13px] text-fg-soft">Se muestra {total} s y se vuelve a ocultar</span>
                        <button
                            type="button"
                            onClick={onReveal}
                            disabled={revealing}
                            className="inline-flex h-11 items-center gap-1.5 rounded-control bg-fg px-3.5 text-sm font-bold text-on-fg hover:brightness-[0.9] disabled:opacity-60"
                        >
                            <Icon name="eye" size={16} />
                            {revealing ? 'Abriendo…' : 'Mostrar'}
                        </button>
                    </div>
                </>
            )}

            <span className="flex items-center gap-1.5 border-t border-line pt-2 text-xs text-fg-muted">
                <Icon name="shield" size={14} />
                No pasó por la IA · quedó en la auditoría
                <span className="ml-auto">{messageTime(notice.at)}</span>
            </span>
            <span aria-live="polite" className="sr-only">
                {announcement}
            </span>
        </article>
    );
}
