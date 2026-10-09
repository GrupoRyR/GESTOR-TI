import { NotificationMatrix } from '@/Components/profile/NotificationMatrix';
import { TelegramCard } from '@/Components/profile/TelegramCard';
import { Avatar } from '@/Components/ui/Avatar';
import { Chip } from '@/Components/ui/Chip';
import { AppLayout } from '@/Layouts/AppLayout';
import type { ProfileEditProps } from '@/types/pages/profile';
import type { PageProps } from '@/types/shared';

/** Tu perfil: identity from Microsoft, Telegram linking and notification preferences. */
export default function ProfileEdit(props: PageProps<ProfileEditProps>) {
    const { profile, telegram, notification_events, auth } = props;

    return (
        <AppLayout shared={props} title="Tu perfil · Gestor TI">
            <div className="grid items-start gap-4 lg:grid-cols-[340px_minmax(0,1fr)] lg:gap-6">
                <h1 className="text-[26px] leading-tight font-bold md:text-[30px] lg:col-span-2">Tu perfil</h1>

                <div className="flex flex-col gap-4">
                    <section aria-label="Datos de tu cuenta" className="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-5">
                        <div className="flex items-center gap-3">
                            <Avatar name={profile.name} src={auth.user.avatar_url} size="lg" tone="strong" />
                            <span className="flex min-w-0 flex-col">
                                <b className="truncate text-[17px] font-bold">{profile.name}</b>
                                <span className="truncate text-[13px] text-fg-muted">{profile.email}</span>
                            </span>
                        </div>
                        <dl className="grid grid-cols-[90px_minmax(0,1fr)] gap-y-2.5 text-sm">
                            <dt className="text-fg-muted">Empresa</dt>
                            <dd>{profile.company_name}</dd>
                            <dt className="text-fg-muted">Área</dt>
                            <dd>{profile.area_name ?? <span className="text-fg-muted">Sin área asignada</span>}</dd>
                            <dt className="text-fg-muted">Roles</dt>
                            <dd className="flex flex-wrap gap-1.5">
                                {profile.roles.length > 0 ? (
                                    profile.roles.map((role) => (
                                        <Chip key={role} size="sm">
                                            {role}
                                        </Chip>
                                    ))
                                ) : (
                                    <span className="text-fg-muted">Sin roles</span>
                                )}
                            </dd>
                        </dl>
                        <p className="text-xs leading-[1.4] text-fg-muted">Nombre, correo y cargo vienen de Microsoft. Se cambian allá.</p>
                    </section>

                    <TelegramCard telegram={telegram} />
                </div>

                <NotificationMatrix events={notification_events} telegramLinked={telegram.linked} />
            </div>
        </AppLayout>
    );
}
