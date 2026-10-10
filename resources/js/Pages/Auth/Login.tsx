import { Link } from '@inertiajs/react';
import { Alert } from '@/Components/ui/Alert';
import { buttonClasses } from '@/Components/ui/Button';
import { GuestLayout } from '@/Layouts/GuestLayout';
import { route } from '@/lib/route';
import type { LoginCompany, LoginProps } from '@/types/pages/auth';
import type { GuestPageProps } from '@/types/shared';

const ERRORS: Record<NonNullable<LoginProps['error']>, { title: string; body: string }> = {
    tenant_not_allowed: {
        title: 'Esa cuenta no es de una empresa habilitada',
        body: 'Entra con el correo de tu empresa. Si crees que es un error, escríbele a TI.',
    },
    microsoft_failed: {
        title: 'Microsoft no respondió',
        body: 'No pudimos terminar el ingreso. Intenta de nuevo en un momento.',
    },
};

/** The four Microsoft squares (brand colors, decorative). */
function MicrosoftMark() {
    return (
        <span aria-hidden="true" className="grid grid-cols-[repeat(2,9px)] gap-0.5">
            <span className="h-[9px] bg-[#F25022]" />
            <span className="h-[9px] bg-[#7FBA00]" />
            <span className="h-[9px] bg-[#00A4EF]" />
            <span className="h-[9px] bg-[#FFB900]" />
        </span>
    );
}

/** "de Segurex o de Derivados" from the enabled companies. */
function companiesPhrase(companies: LoginCompany[]): string | null {
    const names = companies.map((company) => `de ${company.short_name}`);
    if (names.length === 0) return null;
    if (names.length === 1) return names[0] ?? null;
    return `${names.slice(0, -1).join(', ')} o ${names[names.length - 1]}`;
}

function CompanyLogos({ companies }: { companies: LoginCompany[] }) {
    if (companies.length === 0) return null;
    return (
        <ul aria-label="Empresas que usan el Gestor TI" className="flex flex-wrap items-center justify-between gap-x-8 gap-y-4 pt-1 sm:justify-start lg:pt-2">
            {companies.map((company) => (
                <li key={company.id} className="flex items-center">
                    {company.logo_url ? (
                        <>
                            <img src={company.logo_url} alt={company.short_name} className="block h-auto max-h-10 w-[140px] object-contain object-left lg:w-[176px] dark:hidden" />
                            <img
                                src={company.logo_dark_url ?? company.logo_url}
                                alt={company.short_name}
                                className="hidden h-auto max-h-10 w-[140px] object-contain object-left lg:w-[176px] dark:block"
                            />
                        </>
                    ) : (
                        <span className="font-display text-lg font-bold">{company.short_name}</span>
                    )}
                </li>
            ))}
        </ul>
    );
}

/** Sign-in with Microsoft. Desktop: content beside the phases; phone: one column. */
export default function Login(props: GuestPageProps<LoginProps>) {
    const { companies, error, flash } = props;
    const phrase = companiesPhrase(companies);
    const errorCopy = error ? ERRORS[error] : null;

    return (
        <GuestLayout
            title="Entrar · Gestor TI"
            flash={flash}
            footer={
                <Link
                    href={route('login.emergency')}
                    className="inline-flex min-h-11 w-full items-center justify-center underline underline-offset-[3px] hover:text-fg sm:w-auto sm:justify-start"
                >
                    Cuenta de emergencia del administrador
                </Link>
            }
        >
            <div className="flex flex-col gap-3">
                {/* Phone: the five phases shrink to a small rail above the title. */}
                <span aria-hidden="true" className="mb-3 grid w-[140px] grid-cols-5 gap-1 lg:hidden">
                    <span className="h-1.5 rounded-[3px] bg-fg" />
                    <span className="h-1.5 rounded-[3px] bg-fg" />
                    <span className="h-1.5 rounded-[3px] bg-status-scheduled" />
                    <span className="h-1.5 rounded-[3px] bg-line-strong" />
                    <span className="h-1.5 rounded-[3px] bg-line-strong" />
                </span>
                <h1 className="text-[34px] leading-[1.08] font-bold lg:text-[44px] lg:leading-[1.05]">Entra con tu cuenta de trabajo</h1>
                <p className="max-w-[400px] text-base leading-normal text-fg-soft">
                    {phrase ? `Es la misma de tu correo ${phrase}.` : 'Es la misma de tu correo de trabajo.'}
                    <span className="hidden lg:inline"> No necesitas otra contraseña.</span>
                </p>
            </div>

            {errorCopy ? (
                <Alert tone="danger" title={errorCopy.title}>
                    {errorCopy.body}
                </Alert>
            ) : null}

            {/* Full-page redirect to Microsoft: a real link, not an Inertia visit. */}
            <div className="max-w-[400px]">
                <a href={route('auth.microsoft.redirect')} className={buttonClasses({ variant: 'dark', size: 'xl', fullWidth: true })}>
                    <MicrosoftMark />
                    Entrar con Microsoft
                </a>
            </div>

            <CompanyLogos companies={companies} />
        </GuestLayout>
    );
}
