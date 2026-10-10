import { useEffect, useState } from 'react';
import { Icon } from '@/Components/ui/Icon';

interface PropsPanelProps {
    json: string;
    brandNote: string | null;
    onClose: () => void;
}

/** The variant's props as formatted JSON: the contract the controller must send. */
export function PropsPanel({ json, brandNote, onClose }: PropsPanelProps) {
    const [copied, setCopied] = useState<'idle' | 'done' | 'failed'>('idle');

    useEffect(() => setCopied('idle'), [json]);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(json);
            setCopied('done');
        } catch {
            setCopied('failed');
        }
    };

    return (
        <aside
            aria-labelledby="props-panel-title"
            className="flex h-[45%] min-h-0 w-full shrink-0 flex-col border-t border-line bg-surface lg:h-auto lg:w-[min(440px,38%)] lg:border-t-0 lg:border-l"
        >
            <div className="flex items-start gap-2 border-b border-line px-4 py-3">
                <div className="min-w-0 flex-1">
                    <h2 id="props-panel-title" className="text-sm font-bold text-fg">
                        Props del ejemplo
                    </h2>
                    <p className="text-[12px] leading-snug text-fg-muted">Es el contrato: el controlador debe enviar esta misma forma.</p>
                </div>
                <button
                    type="button"
                    onClick={copy}
                    className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-chip border border-line-strong px-3 text-[13px] font-semibold text-fg hover:bg-subtle"
                >
                    <Icon name={copied === 'done' ? 'check' : 'clipboard-check'} size={16} />
                    {copied === 'done' ? 'Copiado' : 'Copiar JSON'}
                </button>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Cerrar props"
                    title="Cerrar props"
                    className="inline-flex size-9 shrink-0 items-center justify-center rounded-chip text-fg-soft hover:bg-subtle"
                >
                    <Icon name="x" size={18} />
                </button>
            </div>
            <p role="status" className="sr-only">
                {copied === 'done' ? 'JSON copiado' : copied === 'failed' ? 'No se pudo copiar el JSON' : ''}
            </p>
            {copied === 'failed' ? (
                <p className="px-4 pt-2 text-[12px] text-status-failure">El navegador no permitió copiar. Selecciona el texto y cópialo.</p>
            ) : null}
            {brandNote !== null ? <p className="px-4 pt-2 text-[12px] text-fg-muted">{brandNote}</p> : null}
            <pre
                tabIndex={0}
                aria-label="Props en JSON"
                className="min-h-0 flex-1 overflow-auto px-4 py-3 font-mono text-[12px] leading-relaxed text-fg-soft"
            >
                {json}
            </pre>
        </aside>
    );
}
