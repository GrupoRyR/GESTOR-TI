import { useId, type ReactNode } from 'react';
import { Chip } from '@/Components/ui/Chip';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { claudeCodeCommand, maskToken } from '@/lib/mcp';
import type { McpToken } from '@/types/mcp';
import { CopyButton, CopyField, CopyStatus, SectionTile, SETUP_ANCHOR, useCopy } from './parts';

/** Never invent Claude's menu paths: the exact steps stay a placeholder. */
function ClaudeSteps() {
    return (
        <span className="inline-flex h-6 items-center rounded-[8px] border border-dashed border-fg-muted bg-ground px-2 font-mono text-xs text-fg-soft">[PASOS EN CLAUDE]</span>
    );
}

function Step({ number, title, children }: { number: number; title: ReactNode; children?: ReactNode }) {
    return (
        <li className="flex items-start gap-3">
            <span aria-hidden="true" className="flex size-7 shrink-0 items-center justify-center rounded-full bg-fg text-sm font-bold text-on-fg">
                {number}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <b className="text-[15px] leading-7">
                    <span className="sr-only">Paso {number}: </span>
                    {title}
                </b>
                {children}
            </div>
        </li>
    );
}

function CommandBlock({ command, label }: { command: string; label: string }) {
    const { state, copy } = useCopy();
    return (
        <div className="flex items-start gap-3 rounded-[12px] bg-[#17191C] py-3.5 pr-2 pl-4 text-[#F2F2EF] ring-1 ring-line ring-inset">
            <code aria-label={label} className="min-w-0 flex-1 py-2 font-mono text-[13px] leading-[1.6] [overflow-wrap:anywhere] whitespace-pre-wrap">
                <span aria-hidden="true" className="text-[#A3A6AD]">
                    ${' '}
                </span>
                {command}
            </code>
            <button
                type="button"
                onClick={() => copy(command)}
                aria-label={`Copiar ${label}`}
                className="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-[10px] px-3 text-[13px] font-bold text-[#F2F2EF] ring-1 ring-[#45484E] ring-inset hover:bg-white/10"
            >
                <Icon name={state === 'copied' ? 'check' : 'copy'} size={15} />
                {state === 'copied' ? 'Copiado' : 'Copiar'}
            </button>
            <CopyStatus state={state} />
        </div>
    );
}

function Card({ icon, title, subtitle, children }: { icon: IconName; title: string; subtitle: string; children: ReactNode }) {
    const titleId = useId();
    return (
        <section aria-labelledby={titleId} className="flex min-w-0 flex-col gap-4 rounded-card border border-line bg-surface p-5">
            <div className="flex items-center gap-3">
                <SectionTile icon={icon} />
                <span className="flex min-w-0 flex-col gap-0.5">
                    <h3 id={titleId} className="font-display text-lg leading-tight font-bold">
                        {title}
                    </h3>
                    <span className="text-[13px] text-fg-muted">{subtitle}</span>
                </span>
            </div>
            {children}
        </section>
    );
}

const PROMPTS: { text: string; access: 'read' | 'write' }[] = [
    { text: 'Busca las solicitudes de cartera', access: 'read' },
    { text: 'Agrega una nota de avance a la tarea T4 de P-07', access: 'write' },
    { text: 'Muéstrame GIA-139 con su documentación', access: 'read' },
];

const TROUBLES: { code: string; title: string; text: string; icon: IconName }[] = [
    { code: '401', title: 'Token vencido o revocado', text: 'Crea un token nuevo en tu perfil y cámbialo en Claude. El anterior ya no sirve.', icon: 'lock' },
    {
        code: '403',
        title: 'El token es de solo lectura',
        text: 'Claude intentó crear o cambiar algo. Usa un token de lectura y escritura si de verdad lo necesitas.',
        icon: 'blocked',
    },
    { code: 'Sin respuesta', title: 'El servidor no responde', text: 'Toca Probar conexión arriba. Si falla, revisa que la dirección termine en /mcp.', icon: 'signal' },
];

export interface SetupSectionProps {
    url: string;
    /** Token shown (masked) in step 3; `null` = the user has no active token. */
    token: McpToken | null;
    onCreate: () => void;
}

/** E-McpSetup "Cómo agregarlo en Claude": generic steps, Claude Code command, example prompts, troubleshooting. */
export function SetupSection({ url, token, onCreate }: SetupSectionProps) {
    const titleId = useId();
    const urlId = useId();
    const tokenId = useId();
    const tokenWhyId = useId();

    return (
        <section id={SETUP_ANCHOR} tabIndex={-1} aria-labelledby={titleId} className="flex scroll-mt-28 flex-col gap-4 outline-none">
            <div className="flex flex-col gap-1.5">
                <h2 id={titleId} className="font-display text-[24px] leading-tight font-bold md:text-[26px]">
                    Cómo agregarlo en Claude
                </h2>
                <p className="max-w-[760px] text-[15px] leading-normal text-fg-soft">
                    Necesitas dos datos: la dirección del servidor y un token tuyo. Los pasos exactos dentro de Claude pueden cambiar; aquí van los generales.
                </p>
            </div>

            <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-5">
                <Card icon="chat" title="Claude en la web o en el escritorio" subtitle="Como conector personalizado">
                    <ol className="flex flex-col gap-4">
                        <Step number={1} title="En Claude, agrega un conector personalizado">
                            <span className="flex flex-wrap items-center gap-1.5 text-sm leading-[1.45] text-fg-soft">
                                Dónde está: <ClaudeSteps /> · ponle un nombre como Gestor IA.
                            </span>
                        </Step>
                        <Step number={2} title="Pega la dirección del servidor">
                            <CopyField id={urlId} label="Dirección del servidor" value={url} ariaCopyLabel="Copiar dirección del servidor" />
                        </Step>
                        <Step number={3} title="Pega tu token donde Claude pida la autenticación">
                            {token ? (
                                <div className="flex min-w-0 flex-col gap-1.5">
                                    <label htmlFor={tokenId} className="text-[13px] font-semibold">
                                        Token · {token.name}
                                    </label>
                                    <div className="flex gap-2 max-sm:flex-col">
                                        <input
                                            id={tokenId}
                                            type="text"
                                            readOnly
                                            value={`••••••••••••••••${maskToken(token.last4).slice(1)}`}
                                            aria-describedby={tokenWhyId}
                                            className="h-11 min-w-0 flex-1 rounded-control border border-line-strong bg-ground px-3.5 font-mono text-[13px] text-fg-muted outline-none"
                                        />
                                        <CopyButton text="" disabled ariaLabel="Copiar token (no disponible)" />
                                    </div>
                                    <span id={tokenWhyId} className="text-xs leading-snug text-fg-muted">
                                        El valor completo solo se vio al crearlo. Pega el que guardaste o{' '}
                                        <button type="button" onClick={onCreate} className="font-semibold text-fg underline underline-offset-2">
                                            crea otro token
                                        </button>
                                        .
                                    </span>
                                </div>
                            ) : (
                                <span className="text-sm text-fg-soft">
                                    Todavía no tienes tokens.{' '}
                                    <button type="button" onClick={onCreate} className="min-h-11 font-semibold text-fg underline underline-offset-2">
                                        Crea uno
                                    </button>{' '}
                                    y cópialo apenas lo veas.
                                </span>
                            )}
                            <span className="flex flex-wrap items-center gap-1.5 text-[13px] text-fg-soft">
                                Dónde va: <ClaudeSteps />
                            </span>
                        </Step>
                        <Step number={4} title="Actívalo en una conversación y prueba">
                            <span className="text-sm leading-[1.45] text-fg-soft">
                                Escribe uno de los mensajes de ejemplo. Claude te pide permiso antes de usar cada herramienta.
                            </span>
                        </Step>
                    </ol>
                </Card>

                <div className="flex min-w-0 flex-col gap-4 lg:gap-5">
                    <Card icon="terminal" title="Claude Code" subtitle="En la terminal de tu equipo">
                        <ol className="flex flex-col gap-4">
                            <Step number={1} title="Ejecuta este comando">
                                <CommandBlock command={claudeCodeCommand(url)} label="comando para agregar el conector" />
                                <span className="text-[13px] leading-snug text-fg-soft">
                                    Cambia <code className="font-mono text-xs">&lt;token&gt;</code> por tu token. No lo guardes en el repositorio.
                                </span>
                            </Step>
                            <Step number={2} title="Revisa que quedó conectado">
                                <CommandBlock command="claude mcp list" label="comando para ver los conectores" />
                                <span className="text-[13px] leading-snug text-fg-soft">
                                    Debe aparecer <code className="font-mono text-xs">gestor-ia</code> como conectado.
                                </span>
                            </Step>
                        </ol>
                    </Card>

                    <section aria-labelledby={`${titleId}-prompts`} className="flex min-w-0 flex-col gap-3 rounded-card border border-line bg-surface p-5">
                        <h3 id={`${titleId}-prompts`} className="font-display text-lg leading-tight font-bold">
                            Prueba con estos mensajes
                        </h3>
                        <ul className="flex flex-col gap-2">
                            {PROMPTS.map((prompt) => (
                                <li key={prompt.text} className="flex min-h-11 items-center gap-2.5 rounded-[12px] bg-ground py-1 pl-3">
                                    <span className="min-w-0 flex-1 text-sm">“{prompt.text}”</span>
                                    <Chip tone={prompt.access === 'read' ? 'slate' : 'amber'} icon={prompt.access === 'read' ? 'eye' : 'edit'} size="sm">
                                        {prompt.access === 'read' ? 'lectura' : 'escritura'}
                                    </Chip>
                                    <CopyButton text={prompt.text} iconOnly ariaLabel={`Copiar mensaje: ${prompt.text}`} />
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            </div>

            <section aria-labelledby={`${titleId}-fail`} className="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-5">
                <h3 id={`${titleId}-fail`} className="font-display text-lg leading-tight font-bold">
                    Si algo falla
                </h3>
                <dl className="grid gap-4 md:grid-cols-3">
                    {TROUBLES.map((trouble) => (
                        <div key={trouble.code} className="flex items-start gap-3">
                            <SectionTile icon={trouble.icon} tone="red" size={36} />
                            <div className="flex min-w-0 flex-col gap-1">
                                <dt className="flex flex-wrap items-center gap-2 text-sm font-bold">
                                    <code className="rounded-[6px] bg-subtle px-1.5 py-0.5 font-mono text-xs">{trouble.code}</code>
                                    {trouble.title}
                                </dt>
                                <dd className="text-[13px] leading-[1.45] text-fg-soft">{trouble.text}</dd>
                            </div>
                        </div>
                    ))}
                </dl>
            </section>
        </section>
    );
}
