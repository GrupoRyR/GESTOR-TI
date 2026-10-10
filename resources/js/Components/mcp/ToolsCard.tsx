import { useId } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { MCP_TOOLS } from '@/lib/mcp';
import { SectionTile, ToolTile } from './parts';

/** E-McpTokens, left card: what Claude can do, read/write marks and what it never returns. */
export function ToolsCard() {
    const titleId = useId();
    return (
        <section aria-labelledby={titleId} className="flex min-w-0 flex-col gap-3.5 rounded-card border border-line bg-surface p-5">
            <div className="flex items-center gap-3">
                <SectionTile icon="plug" tone="brand" />
                <span className="flex min-w-0 flex-col gap-0.5">
                    <h2 id={titleId} className="font-display text-lg leading-tight font-bold">
                        Qué puede hacer Claude con el conector
                    </h2>
                    <span className="text-[13px] text-fg-muted">Se agrega en Claude como conector personalizado. No usa la API de IA de pago.</span>
                </span>
            </div>
            <ul aria-label="Herramientas del conector" className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                {MCP_TOOLS.map((tool) => (
                    <li key={tool.key} className="flex min-w-0 items-center gap-2.5 rounded-[12px] border border-line bg-surface px-3 py-2.5">
                        <ToolTile access={tool.access} />
                        <span className="flex min-w-0 flex-col gap-px">
                            <b className="truncate text-[13px] font-semibold">{tool.label}</b>
                            <code className="truncate font-mono text-[11px] text-fg-muted">{tool.key}</code>
                            <span className={tool.access === 'read' ? 'text-xs font-semibold text-status-local' : 'text-xs font-semibold text-status-maintenance'}>
                                {tool.access === 'read' ? 'Solo lectura' : 'Escritura'}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
            <p className="flex items-start gap-2.5 rounded-[12px] bg-ground px-3.5 py-3 text-[13px] leading-[1.45] text-fg-soft">
                <Icon name="shield" size={16} className="mt-0.5 shrink-0" />
                <span>
                    <b className="text-fg">Nunca devuelve</b> contenido de la Bóveda, credenciales, tarifas, costos ni salarios. Cada llamada queda registrada: quién, qué
                    herramienta, cuándo y con qué resultado.
                </span>
            </p>
        </section>
    );
}
