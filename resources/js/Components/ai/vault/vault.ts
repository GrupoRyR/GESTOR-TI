/**
 * Vault helpers (boards B-VaultLocked/Open/Phone): the life of a revealed
 * value, rotation states, labels and the auto-lock countdown. A revealed
 * value lives only in component memory for at most `REVEAL_SECONDS`, counted
 * from the moment it arrives; `tickReveal` returns null at the deadline and
 * the page then drops it (and wipes the Inertia prop).
 */
import type { IconName } from '../../ui/Icon';
import { formatDate, formatShortDate } from '../../../lib/format';
import { secondsLeft } from '../../../lib/countdown';
import type { VaultAction, VaultEnvironment, VaultSecretType } from '../../../types/ai';

export const REVEAL_SECONDS = 30;
/** The clipboard is wiped this long after "Copiar". */
export const CLIPBOARD_SECONDS = 30;
/** Rotation alerts start this many days before the expiry date. */
export const ROTATION_WARNING_DAYS = 30;

// ---- revealed value -----------------------------------------------------------

export interface ShownSecret {
    itemId: number;
    value: string;
    /** Epoch ms when the value must disappear. */
    hideAt: number;
}

/** Start showing a value received at `receivedAt` (epoch ms); never longer than 30 s. */
export function startReveal(revealed: { item_id: number; value: string; expires_in_seconds: number } | null, receivedAt: number): ShownSecret | null {
    if (!revealed || revealed.value === '' || revealed.expires_in_seconds <= 0) return null;
    const seconds = Math.min(revealed.expires_in_seconds, REVEAL_SECONDS);
    return { itemId: revealed.item_id, value: revealed.value, hideAt: receivedAt + seconds * 1000 };
}

/** Whole seconds left (rounded up), 0 when nothing is shown. */
export function revealLeft(shown: ShownSecret | null, now: number): number {
    if (!shown) return 0;
    return Math.max(0, Math.ceil((shown.hideAt - now) / 1000));
}

/** Same object while time is left; null once the deadline passed (the value must be cleared). */
export function tickReveal(shown: ShownSecret | null, now: number): ShownSecret | null {
    if (!shown || now >= shown.hideAt) return null;
    return shown;
}

// ---- rotation -----------------------------------------------------------------

export type RotationLevel = 'expired' | 'soon' | 'ok' | 'pending' | 'none';

export interface RotationState {
    level: RotationLevel;
    /** Days until the expiry date (negative when past); null without a date. */
    days: number | null;
    /** Table cell: "19 oct", "3 ene 2027", "Falta recibirla", "—". */
    date: string;
    /** "Rotar en 12 días", "Venció hace 2 días". */
    sentence: string;
}

const DAY = 86_400_000;

function dayNumber(date: string): number {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(date);
    if (!match) throw new RangeError(`Invalid ISO date: ${date}`);
    return Math.round(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])) / DAY);
}

const plural = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`;

interface RotationLike {
    expires_at: string | null;
    expiry_note: string | null;
}

export function rotationState(item: RotationLike, today: string): RotationState {
    if (item.expires_at === null) {
        if (item.expiry_note) return { level: 'pending', days: null, date: item.expiry_note, sentence: item.expiry_note };
        return { level: 'none', days: null, date: '—', sentence: 'Sin vencimiento' };
    }
    const days = dayNumber(item.expires_at) - dayNumber(today);
    const date = item.expires_at.slice(0, 4) === today.slice(0, 4) ? formatShortDate(item.expires_at) : formatDate(`${item.expires_at.slice(0, 10)}T12:00:00-05:00`);
    if (days < 0) return { level: 'expired', days, date, sentence: `Venció hace ${plural(-days, 'día', 'días')}` };
    const sentence = days === 0 ? 'Rotar hoy' : days === 1 ? 'Rotar mañana' : `Rotar en ${days} días`;
    return { level: days <= ROTATION_WARNING_DAYS ? 'soon' : 'ok', days, date, sentence };
}

/** Expired and soon-to-expire items, most urgent first. */
export function vaultAlerts<T extends RotationLike>(items: readonly T[], today: string): { item: T; state: RotationState }[] {
    return items
        .map((item) => ({ item, state: rotationState(item, today) }))
        .filter(({ state }) => state.level === 'expired' || state.level === 'soon')
        .sort((a, b) => (a.state.days ?? 0) - (b.state.days ?? 0));
}

/** "Cada 90 días", "Cada año", "Sin rotación". */
export function rotationText(days: number | null): string {
    if (days === null || days <= 0) return 'Sin rotación';
    if (days === 365) return 'Cada año';
    return days === 1 ? 'Cada día' : `Cada ${days} días`;
}

// ---- session ------------------------------------------------------------------

export type VaultSessionState = { state: 'locked' } | { state: 'open'; confirmed_at: string; expires_at: string };

/** Seconds until the vault locks itself; 0 when locked. */
export function sessionLeft(session: VaultSessionState, now: Date): number {
    return session.state === 'open' ? secondsLeft(session.expires_at, now) : 0;
}

// ---- labels -------------------------------------------------------------------

export const VAULT_TYPE_LABELS: Record<VaultSecretType, string> = {
    api_key: 'Clave de API',
    user_password: 'Usuario y contraseña',
    connection_string: 'Cadena de conexión',
    token: 'Token',
};

export const VAULT_ENV_LABELS: Record<VaultEnvironment, string> = { production: 'Producción', testing: 'Pruebas' };

export const VAULT_ACTIONS: Record<VaultAction, { label: string; icon: IconName }> = {
    viewed: { label: 'Vio', icon: 'eye' },
    copied: { label: 'Copió', icon: 'copy' },
    changed: { label: 'Cambió', icon: 'edit' },
    created: { label: 'Creó', icon: 'plus' },
    deleted: { label: 'Borró', icon: 'x' },
    blocked: { label: 'Intento bloqueado', icon: 'blocked' },
};

// ---- edit form ----------------------------------------------------------------

/** Rotation choices of the edit form (null = "No rotar"). */
export const ROTATION_OPTIONS: (number | null)[] = [30, 60, 90, 180, null];

/** Days before the next rotation when the holder gets the Telegram reminder. */
export const ROTATION_REMINDER_DAYS = 7;

/** "Escribe el nombre para confirmar": exact, case-sensitive; only surrounding spaces are ignored. */
export function deleteConfirmMatches(typed: string, name: string): boolean {
    return name !== '' && typed.trim() === name;
}

/** Calendar date (YYYY-MM-DD) `days` after `base`; null without rotation or base. */
export function nextRotation(base: string | null, days: number | null): string | null {
    if (base === null || days === null || days <= 0) return null;
    const date = new Date((dayNumber(base) + days) * DAY);
    return date.toISOString().slice(0, 10);
}

/** The rotation restarts today when a new value is saved (or when the item never rotated). */
export function rotationBase({ lastRotatedAt, today, valueChanged }: { lastRotatedAt: string | null; today: string; valueChanged: boolean }): string {
    return valueChanged || lastRotatedAt === null ? today : lastRotatedAt.slice(0, 10);
}

/** "19 oct · en 12 días", "8 oct · mañana", "Sin rotación". */
export function nextRotationText(next: string | null, today: string): string {
    if (next === null) return 'Sin rotación';
    const state = rotationState({ expires_at: next, expiry_note: null }, today);
    const days = state.days ?? 0;
    const when = days < 0 ? state.sentence.toLowerCase() : days === 0 ? 'hoy' : days === 1 ? 'mañana' : `en ${days} días`;
    return `${state.date} · ${when}`;
}
