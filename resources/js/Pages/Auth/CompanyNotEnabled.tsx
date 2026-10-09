import { buttonClasses } from '@/Components/ui/Button';
import { StateTile } from '@/Components/ui/EmptyState';
import { GuestLayout } from '@/Layouts/GuestLayout';
import { route } from '@/lib/route';
import type { CompanyNotEnabledProps } from '@/types/pages/auth';
import type { GuestPageProps } from '@/types/shared';

/** "de Segurex y de Derivados" from the enabled companies in the guest shared props. */
function enabledPhrase(names: string[]): string {
    const parts = names.map((name) => `de ${name}`);
    if (parts.length <= 1) return parts[0] ?? 'de las empresas habilitadas';
    return `${parts.slice(0, -1).join(', ')} y ${parts[parts.length - 1]}`;
}

/** Microsoft sign-in worked, but the account belongs to a tenant that is not enabled. */
export default function CompanyNotEnabled(props: GuestPageProps<CompanyNotEnabledProps>) {
    const { email, support_email, companies, flash } = props;

    return (
        <GuestLayout title="Tu empresa no usa el Gestor TI" flash={flash}>
            <div className="flex flex-col gap-5">
                <StateTile icon="building" />
                <h1 className="text-[28px] leading-[1.1] font-bold lg:text-[36px]">Tu empresa todavía no usa el Gestor TI</h1>
                <p className="text-[15px] leading-normal text-fg-soft">
                    Entraste como <b className="font-bold break-all text-fg">{email}</b>. Solo pueden entrar cuentas{' '}
                    {enabledPhrase(companies.map((company) => company.short_name))}.
                </p>
            </div>
            <div className="flex flex-col gap-2.5">
                {/* Full-page redirect to Microsoft's account picker. */}
                <a
                    href={route('auth.microsoft.redirect', { prompt: 'select_account' })}
                    className={buttonClasses({ variant: 'dark', size: 'lg', fullWidth: true })}
                >
                    Entrar con otra cuenta
                </a>
                {support_email ? (
                    <a
                        href={`mailto:${support_email}`}
                        className="flex min-h-11 items-center justify-center text-sm font-semibold underline underline-offset-[3px] hover:text-fg"
                    >
                        Escribir a TI: {support_email}
                    </a>
                ) : null}
            </div>
        </GuestLayout>
    );
}
