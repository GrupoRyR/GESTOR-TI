/**
 * Mocks for Profile/Edit and Profile/TelegramLink.
 */
import type { NotificationEvent, ProfileEditProps, TelegramLinkProps } from '@/types/pages/profile';
import type { PageProps } from '@/types/shared';
import { sharedFor, type MockVariant } from './shared';

type EditPage = PageProps<ProfileEditProps>;
type TelegramPage = PageProps<TelegramLinkProps>;

const event = (key: string, label: string, hint: string, mail: boolean, telegram: boolean, database: boolean): NotificationEvent => ({
    key,
    label,
    hint,
    channels: { mail, telegram, database },
});

/** IT events (Jhon). */
export const IT_EVENTS: NotificationEvent[] = [
    event('request_created', 'Llega una solicitud nueva', 'De cualquier área de las dos empresas', false, true, true),
    event('first_response_overdue', 'Una solicitud pasa la primera respuesta', 'Se vence el plazo configurado', true, true, true),
    event('approval_requested', 'Una solicitud espera tu aprobación', 'Con botones Aprobar y Rechazar en Telegram', true, true, true),
    event('question_answered', 'Alguien responde una pregunta de TI', 'En la conversación de la solicitud', false, true, true),
    event('requirement_overdue', 'Un requerimiento a un usuario se vence', 'Por ejemplo, una API que no llega', true, true, true),
    event('credential_expiring', 'Una credencial está por vencer', '30, 15 y 7 días antes', true, true, false),
    event('own_request_status', 'Cambia el estado de tus solicitudes', 'Las que tú pediste', true, false, true),
    event('weekly_digest', 'Resumen semanal', 'Lunes 7:00 a. m.', true, false, false),
];

/** Requester events (Andrés). */
const REQUESTER_EVENTS: NotificationEvent[] = [
    event('own_request_status', 'Cambia el estado de tus solicitudes', 'Las que tú pediste', true, false, true),
    event('question_asked', 'TI te hace una pregunta', 'Para seguir con tu solicitud', true, false, true),
    event('delivery_ready', 'Tu solicitud está lista para probar', 'Te pedimos validar los criterios', true, false, true),
];

export const profileProps = {
    ...sharedFor('jhon'),
    profile: {
        name: 'Jhon Castro',
        email: 'jhon.castro@segurex.com',
        company_name: 'Segurex Latam',
        area_name: 'TI',
        roles: ['Súper Admin', 'Líder IA', 'Aprobador de proyectos'],
    },
    telegram: { linked: true, username: 'jhoncastro', linked_at: '2026-10-03T10:15:00-05:00' },
    notification_events: IT_EVENTS,
} satisfies EditPage;

const telegramBase = {
    ...sharedFor('andres'),
    link_url: 'https://t.me/[BOT_DEL_GESTOR]?start=4f9c2a7e',
    expires_at: '2026-10-07T09:10:00-05:00',
    status: 'waiting',
} satisfies TelegramPage;

/** Expiry nine minutes from when the preview renders, so the countdown reads naturally. */
function inMinutes(minutes: number): string {
    return new Date(Date.now() + minutes * 60_000).toISOString();
}

export const variants: Record<string, MockVariant<EditPage | TelegramPage>> = {
    'edit-linked': { page: 'Profile/Edit', label: 'Perfil · Telegram vinculado', props: profileProps },
    'edit-not-linked': {
        page: 'Profile/Edit',
        label: 'Perfil · solicitante sin Telegram',
        props: {
            ...sharedFor('andres'),
            profile: {
                name: 'Andrés Méndez',
                email: 'andres.mendez@derivadoscol.com',
                company_name: 'Soluciones y Derivados de Colombia S.A.S.',
                area_name: 'Compras',
                roles: ['Usuario final'],
            },
            telegram: { linked: false },
            notification_events: REQUESTER_EVENTS,
        } satisfies EditPage,
    },
    'edit-no-area': {
        page: 'Profile/Edit',
        label: 'Perfil · sin área ni roles',
        props: {
            ...sharedFor('andres'),
            profile: {
                name: 'Diego Restrepo',
                email: 'diego.restrepo@segurex.com',
                company_name: 'Segurex Latam',
                area_name: null,
                roles: [],
            },
            telegram: { linked: false },
            notification_events: REQUESTER_EVENTS,
        } satisfies EditPage,
    },
    'telegram-waiting': {
        page: 'Profile/TelegramLink',
        label: 'Vincular Telegram · esperando',
        get props() {
            return { ...telegramBase, expires_at: inMinutes(9) } satisfies TelegramPage;
        },
    },
    'telegram-linked': {
        page: 'Profile/TelegramLink',
        label: 'Vincular Telegram · vinculado',
        props: { ...telegramBase, status: 'linked' } satisfies TelegramPage,
    },
    'telegram-expired': {
        page: 'Profile/TelegramLink',
        label: 'Vincular Telegram · enlace vencido',
        props: { ...telegramBase, status: 'expired' } satisfies TelegramPage,
    },
};
