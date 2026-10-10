import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { Button } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { Icon } from '@/Components/ui/Icon';
import { formatLatency } from '@/lib/mcp';
import { route } from '@/lib/route';
import type { McpConnectionTest, McpServer } from '@/types/mcp';
import { CopyButton, CopyField, SectionTile, SETUP_ANCHOR, whenText } from './parts';

const FAILURE_LEAD: Record<string, string> = {
    mcp_timeout: 'El servidor no respondió en 10 segundos.',
    mcp_tls: 'El certificado https del servidor no es válido.',
};

function useConnectionTest() {
    const [testing, setTesting] = useState(false);
    const test = () =>
        router.post(
            route('profile.mcp.test'),
            {},
            {
                only: ['connection_test', 'server'],
                preserveScroll: true,
                preserveState: true,
                onStart: () => setTesting(true),
                onFinish: () => setTesting(false),
            },
        );
    return { testing, test };
}

/** E-McpStates "La prueba de conexión falló". */
function TestFailed({ result, now, onRetry, testing }: { result: Extract<McpConnectionTest, { status: 'failed' }>; now: Date; onRetry: () => void; testing: boolean }) {
    const titleId = useId();
    return (
        <div role="alert" aria-labelledby={titleId} className="flex flex-col gap-2.5 rounded-[14px] border border-status-failure-line bg-status-failure-bg/40 p-4">
            <div className="flex items-center gap-2.5">
                <SectionTile icon="x-circle" tone="red" size={36} />
                <h3 id={titleId} className="text-[15px] font-bold">
                    La prueba de conexión falló
                </h3>
            </div>
            <p className="text-sm leading-normal text-fg-soft">{FAILURE_LEAD[result.code] ?? 'El servidor respondió con un error.'} Revisa:</p>
            <ul className="flex list-disc flex-col gap-1 pl-5 text-sm leading-snug text-fg-soft">
                <li>
                    Que la dirección use https y termine en <code className="font-mono text-[13px] text-fg">/mcp</code>
                </li>
                <li>Que el conector esté activo en el hosting</li>
                <li>Que el Gestor abra normal en el navegador</li>
            </ul>
            <span className="text-xs text-fg-muted">
                Último intento: {whenText(result.at, now).toLowerCase()} · código <code className="font-mono">{result.code}</code>
            </span>
            <div className="flex flex-wrap gap-2">
                <Button variant="secondary" icon="refresh" onClick={onRetry} loading={testing}>
                    Volver a probar
                </Button>
                <a href={`#${SETUP_ANCHOR}`} className="flex min-h-11 items-center px-1 text-sm font-semibold underline underline-offset-2">
                    Ver cómo configurarlo
                </a>
            </div>
        </div>
    );
}

/** E-McpStates "El conector aún no está activo en este servidor". */
function ServerInactive({ url, onRetry, testing, onCreate }: { url: string; onRetry: () => void; testing: boolean; onCreate: () => void }) {
    const titleId = useId();
    return (
        <div aria-labelledby={titleId} role="status" className="flex flex-col gap-2.5 rounded-[14px] bg-status-maintenance-bg p-4">
            <div className="flex items-center gap-2.5">
                <SectionTile icon="power" tone="amber" size={36} />
                <h3 id={titleId} className="text-[15px] font-bold text-fg">
                    El conector aún no está activo en este servidor
                </h3>
            </div>
            <p className="text-sm leading-normal text-fg-soft">
                La dirección <code className="font-mono text-[13px] break-all text-fg">{url}</code> todavía no responde. Lo activa quien administra el hosting:{' '}
                <span className="font-semibold text-status-maintenance">[PASO DE ACTIVACIÓN EN EL HOSTING]</span>
            </p>
            <p className="text-sm leading-normal text-fg-soft">Puedes crear tokens desde ya; Claude podrá conectarse cuando esté activo.</p>
            <div className="flex flex-wrap gap-2">
                <Button variant="secondary" icon="refresh" onClick={onRetry} loading={testing}>
                    Probar de nuevo
                </Button>
                <Button variant="secondary" icon="plus" onClick={onCreate}>
                    Crear token
                </Button>
            </div>
        </div>
    );
}

export interface ServerCardProps {
    server: McpServer;
    test: McpConnectionTest | null;
    now: Date;
    onCreate: () => void;
}

/** E-McpTokens, right card: server URL with Copiar and "Probar conexión". */
export function ServerCard({ server, test, now, onCreate }: ServerCardProps) {
    const titleId = useId();
    const urlId = useId();
    const { testing, test: runTest } = useConnectionTest();

    return (
        <section aria-labelledby={titleId} className="flex min-w-0 flex-col gap-3.5 rounded-card border border-line bg-surface p-5">
            <div className="flex items-center gap-3">
                <SectionTile icon="server" />
                <span className="flex min-w-0 flex-col gap-0.5">
                    <h2 id={titleId} className="font-display text-lg leading-tight font-bold">
                        Servidor
                    </h2>
                    <span className="text-[13px] text-fg-muted">La misma dirección para todos tus tokens</span>
                </span>
            </div>
            <CopyField id={urlId} label="Dirección del servidor" value={server.url} ariaCopyLabel="Copiar dirección del servidor" />

            {!server.active ? (
                <ServerInactive url={server.url} onRetry={runTest} testing={testing} onCreate={onCreate} />
            ) : test?.status === 'failed' ? (
                <TestFailed result={test} now={now} onRetry={runTest} testing={testing} />
            ) : (
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="secondary" icon="refresh" onClick={runTest} loading={testing}>
                        Probar conexión
                    </Button>
                    <span role="status" className="flex flex-col gap-0.5">
                        {test?.status === 'ok' ? (
                            <>
                                <Chip tone="green" icon="check" size="sm">
                                    Conectado · respondió en {formatLatency(test.ms)}
                                </Chip>
                                <span className="text-xs text-fg-muted">Probado {whenText(test.at, now).toLowerCase()}</span>
                            </>
                        ) : (
                            <span className="text-[13px] text-fg-muted">Todavía no has probado la conexión.</span>
                        )}
                    </span>
                </div>
            )}
        </section>
    );
}

/** E-McpPhone: compact server block (URL + "Copiar dirección", status + "Probar"). */
export function ServerCompact({ server, test, now, onCreate }: ServerCardProps) {
    const urlId = useId();
    const { testing, test: runTest } = useConnectionTest();
    return (
        <section aria-label="Servidor" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
            <div className="flex min-w-0 flex-col gap-1.5">
                <label htmlFor={urlId} className="text-[13px] font-semibold">
                    Dirección del servidor
                </label>
                <input
                    id={urlId}
                    type="text"
                    readOnly
                    value={server.url}
                    className="h-11 min-w-0 rounded-control border border-line-strong bg-ground px-3.5 font-mono text-[13px] text-fg outline-none focus-visible:outline-2 focus-visible:outline-fg"
                />
                <CopyButton text={server.url} label="Copiar dirección" className="w-full" />
            </div>
            {!server.active ? (
                <ServerInactive url={server.url} onRetry={runTest} testing={testing} onCreate={onCreate} />
            ) : test?.status === 'failed' ? (
                <TestFailed result={test} now={now} onRetry={runTest} testing={testing} />
            ) : (
                <div className="flex items-center justify-between gap-3">
                    <span role="status">
                        {test?.status === 'ok' ? (
                            <Chip tone="green" icon="check" size="sm">
                                Conectado · {formatLatency(test.ms)}
                            </Chip>
                        ) : (
                            <span className="text-[13px] text-fg-muted">Sin probar</span>
                        )}
                    </span>
                    <Button variant="secondary" icon="refresh" onClick={runTest} loading={testing}>
                        Probar
                    </Button>
                </div>
            )}
        </section>
    );
}
