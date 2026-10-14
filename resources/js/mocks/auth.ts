/**
 * Mocks for the guest pages: Auth/Login, Auth/Emergency, Auth/CompanyNotEnabled.
 */
import type { CompanyNotEnabledProps, EmergencyProps, LoginProps } from '@/types/pages/auth';
import type { GuestPageProps } from '@/types/shared';
import { ANDINA, COMPANIES, guestShared, type MockVariant } from './shared';

type LoginPage = GuestPageProps<LoginProps>;
type EmergencyPage = GuestPageProps<EmergencyProps>;
type CompanyNotEnabledPage = GuestPageProps<CompanyNotEnabledProps>;

export const loginProps = {
    ...guestShared(),
    companies: COMPANIES,
    error: null,
} satisfies LoginPage;

export const emergencyProps = {
    ...guestShared(),
    lockout: null,
    attempts_left: null,
} satisfies EmergencyPage;

export const companyNotEnabledProps = {
    ...guestShared(),
    email: 'laura.gomez@proveedorx.com',
    support_email: 'jhon.castro@segurex.com',
} satisfies CompanyNotEnabledPage;

export const variants: Record<string, MockVariant<LoginPage | EmergencyPage | CompanyNotEnabledPage>> = {
    'login-default': { page: 'Auth/Login', label: 'Entrar · normal', props: loginProps },
    'login-tenant-not-allowed': {
        page: 'Auth/Login',
        label: 'Entrar · cuenta de otra empresa',
        props: { ...loginProps, error: 'tenant_not_allowed' } satisfies LoginPage,
    },
    'login-microsoft-failed': {
        page: 'Auth/Login',
        label: 'Entrar · Microsoft no respondió',
        props: { ...loginProps, error: 'microsoft_failed' } satisfies LoginPage,
    },
    'login-three-companies': {
        page: 'Auth/Login',
        label: 'Entrar · tres empresas (una sin logo)',
        props: { ...loginProps, ...guestShared({ companies: [...COMPANIES, ANDINA] }), companies: [...COMPANIES, ANDINA] } satisfies LoginPage,
    },
    'emergency-default': { page: 'Auth/Emergency', label: 'Emergencia · formulario', props: emergencyProps },
    'emergency-attempts': {
        page: 'Auth/Emergency',
        label: 'Emergencia · quedan 2 intentos',
        props: {
            ...emergencyProps,
            attempts_left: 2,
            errors: { password: 'El usuario o la contraseña no coinciden.' },
        } satisfies EmergencyPage,
    },
    'emergency-locked': {
        page: 'Auth/Emergency',
        label: 'Emergencia · bloqueada',
        props: {
            ...emergencyProps,
            lockout: { locked_until: '2026-10-07T15:42:00-05:00' },
            attempts_left: 0,
        } satisfies EmergencyPage,
    },
    'company-not-enabled': { page: 'Auth/CompanyNotEnabled', label: 'Empresa no habilitada', props: companyNotEnabledProps },
    'company-not-enabled-no-support': {
        page: 'Auth/CompanyNotEnabled',
        label: 'Empresa no habilitada · sin correo de TI',
        props: { ...companyNotEnabledProps, support_email: null } satisfies CompanyNotEnabledPage,
    },
};
