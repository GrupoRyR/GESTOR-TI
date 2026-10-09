import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert } from '@/Components/ui/Alert';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { TextField } from '@/Components/ui/TextField';
import { GuestLayout } from '@/Layouts/GuestLayout';
import { formatTime } from '@/lib/format';
import { route } from '@/lib/route';
import type { EmergencyProps } from '@/types/pages/auth';
import type { GuestPageProps } from '@/types/shared';

/** Local username + password, only for the administrator when Microsoft is down. */
export default function Emergency(props: GuestPageProps<EmergencyProps>) {
    const { lockout, attempts_left, flash } = props;
    const form = useForm({ username: '', password: '' });
    const locked = lockout !== null;
    const errors = { ...props.errors, ...form.errors };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(route('login.emergency.store'), { onFinish: () => form.reset('password') });
    };

    return (
        <GuestLayout title="Cuenta de emergencia · Gestor TI" flash={flash}>
            <Link
                href={route('login')}
                className="-mb-2 inline-flex min-h-11 items-center gap-2 self-start text-sm font-semibold no-underline hover:underline"
            >
                <Icon name="chevron-left" size={18} />
                Volver a Entrar con Microsoft
            </Link>

            <div className="flex flex-col gap-2">
                <h1 className="text-[28px] leading-tight font-bold lg:text-[36px]">Cuenta de emergencia</h1>
                <p className="text-[15px] leading-normal text-fg-soft">Solo para el administrador cuando Microsoft no responde.</p>
            </div>

            {locked ? (
                <Alert tone="danger" title="Bloqueada por intentos fallidos">
                    Puedes intentar de nuevo a las {formatTime(lockout.locked_until)}; ya se avisó a los administradores por Telegram.
                </Alert>
            ) : attempts_left !== null && attempts_left > 0 ? (
                <Alert tone="warning" title={attempts_left === 1 ? 'Te queda 1 intento' : `Te quedan ${attempts_left} intentos`}>
                    Después la cuenta se bloquea por un tiempo y se avisa a los administradores.
                </Alert>
            ) : null}

            <form onSubmit={submit} noValidate className="flex flex-col gap-4">
                <TextField
                    label="Usuario"
                    name="username"
                    size="lg"
                    autoComplete="username"
                    autoCapitalize="none"
                    spellCheck={false}
                    required
                    disabled={locked}
                    value={form.data.username}
                    onChange={(event) => form.setData('username', event.target.value)}
                    error={errors.username}
                />
                <TextField
                    label="Contraseña"
                    name="password"
                    type="password"
                    size="lg"
                    autoComplete="current-password"
                    required
                    disabled={locked}
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    error={errors.password}
                />
                <Button type="submit" variant="dark" size="lg" fullWidth disabled={locked} loading={form.processing}>
                    Entrar
                </Button>
            </form>
        </GuestLayout>
    );
}
