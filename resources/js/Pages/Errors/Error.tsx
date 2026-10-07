import { Head } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { StateTile } from '@/Components/ui/EmptyState';
import type { IconName } from '@/Components/ui/Icon';
import { NoPermission } from '@/Components/ui/NoPermission';
import { AppLayout } from '@/Layouts/AppLayout';
import { route } from '@/lib/route';
import type { ErrorPageProps, ErrorStatus } from '@/types/pages/errors';
import type { SharedProps } from '@/types/shared';

const COPY: Record<ErrorStatus, { title: string; body: string; icon: IconName }> = {
    403: {
        title: 'No tienes acceso a esta sección',
        body: 'Si necesitas entrar, pídele el permiso a un administrador del Gestor TI.',
        icon: 'lock',
    },
    404: {
        title: 'No encontramos esta página',
        body: 'Puede que el enlace esté incompleto o que lo que buscabas ya no exista.',
        icon: 'search',
    },
    419: {
        title: 'Tu sesión venció',
        body: 'Por seguridad la cerramos después de un rato sin uso. Recarga la página para seguir donde ibas.',
        icon: 'hourglass',
    },
    500: {
        title: 'Algo falló de nuestro lado',
        body: 'No es nada que hayas hecho. Intenta de nuevo en unos minutos; si sigue pasando, escríbele a TI.',
        icon: 'alert-triangle',
    },
    503: {
        title: 'Estamos haciendo mantenimiento',
        body: 'El Gestor TI vuelve en unos minutos. Lo que ya enviaste quedó guardado.',
        icon: 'refresh',
    },
};

/** Shared props are complete only when the request went through the app middleware. */
function hasShared(props: ErrorPageProps): props is ErrorPageProps & SharedProps {
    return Boolean(
        props.auth?.user && props.companies && props.modules && props.flash && props.current_company_id !== undefined && props.notifications_unread !== undefined,
    );
}

function Action({ status }: { status: ErrorStatus }) {
    if (status === 419) {
        return (
            <Button variant="dark" icon="refresh" onClick={() => window.location.reload()}>
                Recargar la página
            </Button>
        );
    }
    return (
        <Button variant="dark" href={route('home')}>
            Ir al inicio
        </Button>
    );
}

function Message({ status }: { status: ErrorStatus }) {
    const copy = COPY[status];
    return (
        <section className="flex flex-col items-start gap-3.5 rounded-card border border-line bg-surface p-6 md:p-8">
            <StateTile icon={copy.icon} tone={status >= 500 ? 'danger' : 'neutral'} />
            <p className="tabular text-[13px] font-bold text-fg-muted">Error {status}</p>
            <h1 className="text-[26px] leading-tight font-bold md:text-[30px]">{copy.title}</h1>
            <p className="max-w-[480px] text-[15px] leading-normal text-fg-soft">{copy.body}</p>
            <Action status={status} />
        </section>
    );
}

/** 403 / 404 / 419 / 500 / 503, inside the app shell when possible, else on a plain page. */
export default function ErrorPage(props: ErrorPageProps) {
    const { status } = props;
    const copy = COPY[status];
    const title = `${copy.title} · Gestor TI`;

    if (hasShared(props)) {
        return (
            <AppLayout shared={props} title={title}>
                <div className="mx-auto w-full max-w-[640px] pt-2 md:pt-8">
                    {status === 403 ? <NoPermission title={copy.title} description={copy.body} /> : <Message status={status} />}
                </div>
            </AppLayout>
        );
    }

    return (
        <div className="flex min-h-dvh flex-col bg-ground px-4 py-8 text-fg md:px-8">
            <Head title={title} />
            <div className="flex items-center gap-2.5">
                <span
                    aria-hidden="true"
                    className="flex size-[34px] items-center justify-center rounded-[10px] bg-fg font-display text-sm font-bold text-on-fg"
                >
                    TI
                </span>
                <span className="text-[15px] font-bold">Gestor TI · Grupo RYR</span>
            </div>
            <main className="mx-auto flex w-full max-w-[560px] flex-1 flex-col justify-center py-10">
                <Message status={status} />
            </main>
        </div>
    );
}
