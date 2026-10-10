/**
 * Perfil (`Pages/Profile/Edit.tsx` → `Profile/Edit`, `Pages/Profile/TelegramLink.tsx` → `Profile/TelegramLink`).
 * Any signed-in user; no extra permission (`ai.mcp.use` only shows the "Conector para Claude" card).
 *
 * Ziggy routes used:
 * - `profile.edit`                   GET     —                 back link from TelegramLink
 * - `profile.notifications.update`   PUT     body `{ event: key, channel: 'mail' | 'telegram' | 'database', enabled: boolean }`
 *                                            sent on every checkbox change (auto-save, `preserveScroll`)
 * - `profile.telegram.create`        GET     —                 "Vincular Telegram" (TelegramLink page)
 * - `profile.telegram.store`         POST    —                 "Generar otro enlace" (new link, back to TelegramLink)
 * - `profile.telegram.destroy`       DELETE  —                 "Desvincular"
 * - `profile.mcp`                    GET     —                 "Configurar conector" card on Profile/Edit, shown only
 *                                            with `ai.mcp.use` (see `types/pages/profile-mcp.ts`)
 *
 * TelegramLink polls itself with `router.reload({ only: ['status'] })` every
 * 4 s while `status === 'waiting'`.
 */
export type NotificationChannel = 'mail' | 'telegram' | 'database';

export interface NotificationEvent {
    key: string;
    /** "Una solicitud espera tu aprobación". */
    label: string;
    /** Short explanation under the label. */
    hint: string;
    channels: Record<NotificationChannel, boolean>;
}

export type TelegramState = { linked: true; username: string; linked_at: string } | { linked: false };

export interface ProfileEditProps {
    profile: {
        name: string;
        email: string;
        company_name: string;
        area_name: string | null;
        roles: string[];
    };
    telegram: TelegramState;
    notification_events: NotificationEvent[];
}

export interface TelegramLinkProps {
    /** Deep link to the bot with the one-time token (https://t.me/…?start=…). */
    link_url: string;
    expires_at: string;
    status: 'waiting' | 'linked' | 'expired';
}
