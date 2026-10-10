import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityPanel } from '@/Components/mcp/ActivityPanel';
import { CreatedTokenDialog } from '@/Components/mcp/CreatedTokenDialog';
import { CreateTokenDialog, type CreateTokenPayload } from '@/Components/mcp/CreateTokenDialog';
import { McpNoPermission } from '@/Components/mcp/McpNoPermission';
import { ACTIVITY_ANCHOR, scrollToAnchor, SETUP_ANCHOR } from '@/Components/mcp/parts';
import { ServerCard, ServerCompact } from '@/Components/mcp/ServerCard';
import { SetupSection } from '@/Components/mcp/SetupSection';
import { TokensList } from '@/Components/mcp/TokensList';
import { ToolsCard } from '@/Components/mcp/ToolsCard';
import { Button, buttonClasses } from '@/Components/ui/Button';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { AppLayout } from '@/Layouts/AppLayout';
import { can } from '@/lib/can';
import { maskToken, tokenStatus } from '@/lib/mcp';
import { route } from '@/lib/route';
import type { McpToken } from '@/types/mcp';
import type { ProfileMcpProps } from '@/types/pages/profile-mcp';
import type { PageProps } from '@/types/shared';

const TITLE = 'Conector para Claude · Gestor TI';

/** Drop the show-once value from the Inertia page (and so from this history entry) when the router supports it. */
function wipeCreatedToken() {
    const replace = (router as { replaceProp?: (name: string, value: unknown) => void }).replaceProp;
    if (typeof replace === 'function') replace.call(router, 'created_token', null);
}

function Breadcrumb() {
    return (
        <nav aria-label="Ruta" className="hidden items-center gap-1.5 text-[13px] text-fg-muted md:flex">
            <Link href={route('profile.edit')} className="text-fg-soft underline-offset-2 hover:underline">
                Tu perfil
            </Link>
            <Icon name="chevron-right" size={14} />
            <span aria-current="page" className="font-semibold text-fg">
                Conector para Claude
            </span>
        </nav>
    );
}

function PhoneHeader({ allowed }: { allowed: boolean }) {
    return (
        <header className="sticky top-0 z-20 flex h-[60px] items-center gap-2 border-b border-line bg-surface pr-2 pl-2">
            <Link
                href={route('profile.edit')}
                aria-label="Volver al perfil"
                className="flex size-11 shrink-0 items-center justify-center rounded-control text-fg hover:bg-subtle"
            >
                <Icon name="chevron-left" size={20} />
            </Link>
            <h1 className="min-w-0 flex-1 truncate font-display text-[17px] leading-tight font-bold tracking-[-0.02em]">Conector para Claude</h1>
            {allowed ? (
                <a href={`#${SETUP_ANCHOR}`} className="flex min-h-11 shrink-0 items-center px-2 text-sm font-semibold text-fg underline-offset-2 hover:underline">
                    Cómo agregarlo
                </a>
            ) : null}
        </header>
    );
}

/** E-Mcp*: personal MCP tokens, server check, setup steps and audited activity. */
export default function ProfileMcp(props: PageProps<ProfileMcpProps>) {
    const { server, tokens, connection_test, created_token, selected_token, activity, mcp_holders, preview, auth } = props;
    const now = useMemo(() => new Date(props.now), [props.now]);
    const allowed = can(auth.user, 'ai.mcp.use');

    const [create, setCreate] = useState<{ open: boolean; key: number; initial?: Partial<CreateTokenPayload> }>({ open: preview?.dialog === 'create', key: 0 });
    const [revoking, setRevoking] = useState<McpToken | null>(() =>
        preview?.dialog === 'revoke' ? (tokens.find((token) => token.id === preview.revoke_token_id) ?? null) : null,
    );
    const [processing, setProcessing] = useState(false);
    const [shown, setShown] = useState(created_token);

    useEffect(() => setShown(created_token), [created_token]);

    // After an "Actividad" partial reload, bring the panel into view.
    const activityKey = activity ? String(activity.token_id ?? 'all') : null;
    const lastActivityKey = useRef(activityKey);
    useEffect(() => {
        if (activityKey !== null && activityKey !== lastActivityKey.current) window.requestAnimationFrame(() => scrollToAnchor(ACTIVITY_ANCHOR));
        lastActivityKey.current = activityKey;
    }, [activityKey]);

    useEffect(() => {
        if (preview?.anchor) scrollToAnchor(preview.anchor === 'setup' ? SETUP_ANCHOR : ACTIVITY_ANCHOR);
    }, [preview?.anchor]);

    const live = tokens.filter((token) => !token.revoked_at);
    const usable = live.filter((token) => tokenStatus(token, now).kind !== 'expired');

    const openCreate = (initial?: Partial<CreateTokenPayload>) => setCreate((current) => ({ open: true, key: current.key + 1, initial }));
    const replace = (token: McpToken) => openCreate({ name: token.name, scope: token.scope });

    const doneWithToken = (next?: 'setup') => {
        setShown(null);
        wipeCreatedToken();
        if (next === 'setup') window.requestAnimationFrame(() => scrollToAnchor(SETUP_ANCHOR));
    };

    const confirmRevoke = () => {
        if (!revoking) return;
        router.delete(route('profile.mcp.tokens.destroy', { token: revoking.id }), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setRevoking(null);
            },
        });
    };

    if (!allowed) {
        return (
            <AppLayout shared={props} title={TITLE} phoneHeader={<PhoneHeader allowed={false} />}>
                <div className="flex flex-col gap-4">
                    <Breadcrumb />
                    <h1 className="font-display text-[30px] leading-tight font-bold tracking-[-0.02em] max-md:hidden">Conector para Claude</h1>
                    <McpNoPermission holders={mcp_holders} />
                </div>
            </AppLayout>
        );
    }

    const revokingExpired = revoking ? tokenStatus(revoking, now).kind === 'expired' : false;
    const others = revoking ? usable.filter((token) => token.id !== revoking.id) : [];

    return (
        <AppLayout shared={props} title={TITLE} phoneHeader={<PhoneHeader allowed />}>
            <div className="flex flex-col gap-[18px]">
                <Breadcrumb />
                <div className="-mt-2 flex flex-wrap items-end justify-between gap-x-6 gap-y-3 max-md:mt-0">
                    <div className="flex min-w-0 flex-col gap-1.5">
                        <h1 className="font-display text-[30px] leading-tight font-bold tracking-[-0.02em] max-md:hidden">Conector para Claude</h1>
                        <p className="text-[15px] leading-normal text-fg-soft max-md:hidden">
                            Consulta y actualiza el Gestor IA desde una conversación con Claude. Tus tokens son personales.
                        </p>
                        <p className="text-[15px] leading-normal text-fg-soft md:hidden">Claude consulta y actualiza el Gestor IA. Nunca ve la Bóveda, costos ni salarios.</p>
                    </div>
                    <div className="flex gap-2 max-md:hidden">
                        <a href={`#${SETUP_ANCHOR}`} className={buttonClasses({ variant: 'secondary' })}>
                            <Icon name="book" size={16} />
                            Cómo agregarlo en Claude
                        </a>
                        <Button icon="plus" onClick={() => openCreate()}>
                            Crear token
                        </Button>
                    </div>
                </div>

                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_460px] lg:gap-5">
                    <div className="max-md:hidden">
                        <ToolsCard />
                    </div>
                    <div className="max-md:hidden">
                        <ServerCard server={server} test={connection_test} now={now} onCreate={() => openCreate()} />
                    </div>
                    <div className="md:hidden">
                        <ServerCompact server={server} test={connection_test} now={now} onCreate={() => openCreate()} />
                    </div>
                </div>

                <TokensList tokens={tokens} now={now} onCreate={() => openCreate()} onRevoke={setRevoking} onReplace={replace} />

                {activity ? <ActivityPanel activity={activity} selected={selected_token} tokens={live} now={now} onRevoke={setRevoking} /> : null}

                <SetupSection url={server.url} token={usable[0] ?? null} onCreate={() => openCreate()} />

                <div className="sticky bottom-[calc(66px+max(6px,env(safe-area-inset-bottom)))] z-10 -mx-4 -mb-10 flex flex-col gap-1.5 border-t border-line bg-surface px-4 pt-3 pb-3 md:hidden">
                    <Button icon="plus" size="lg" fullWidth onClick={() => openCreate()}>
                        Crear token
                    </Button>
                    <span className="flex items-center justify-center gap-1.5 text-xs text-fg-muted">
                        <Icon name="info" size={13} />
                        Copiar y pegar un token largo es más fácil desde el computador.
                    </span>
                </div>
            </div>

            <CreateTokenDialog key={create.key} open={create.open} onClose={() => setCreate((current) => ({ ...current, open: false }))} now={now} initial={create.initial} />

            <CreatedTokenDialog token={shown} onDone={doneWithToken} />

            <ConfirmDialog
                open={revoking !== null}
                onClose={() => setRevoking(null)}
                onConfirm={confirmRevoke}
                processing={processing}
                title={revoking ? (revokingExpired ? `¿Quitar ${revoking.name} de la lista?` : `¿Revocar ${revoking.name}?`) : ''}
                confirmLabel={revokingExpired ? 'Quitar de la lista' : 'Revocar token'}
                description={
                    revoking ? (
                        <span className="flex flex-col gap-2">
                            {revokingExpired ? (
                                <span>Ya venció, así que no funciona. Pasa a Revocados y su actividad se conserva para la auditoría.</span>
                            ) : (
                                <>
                                    <b className="text-fg">Claude dejará de tener acceso de inmediato.</b>
                                    <span>
                                        Las conversaciones que usen el token {maskToken(revoking.last4)} recibirán un error 401. No se puede deshacer: si lo necesitas otra
                                        vez, crea uno nuevo.
                                    </span>
                                    <span className="flex items-start gap-1.5 text-fg-muted">
                                        <Icon name="info" size={14} className="mt-0.5 shrink-0" />
                                        {others.length > 0
                                            ? `${others.map((token) => token.name).join(', ')} ${others.length === 1 ? 'sigue' : 'siguen'} funcionando. La actividad registrada se conserva.`
                                            : 'La actividad registrada se conserva.'}
                                    </span>
                                </>
                            )}
                        </span>
                    ) : null
                }
            />
        </AppLayout>
    );
}
