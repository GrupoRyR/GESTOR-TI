/**
 * Auth pages (guest: `GuestPageProps<…>`, no permissions required).
 *
 * Ziggy routes used:
 * - `login`                    GET   —                        Auth/Login (back link from Emergency)
 * - `auth.microsoft.redirect`  GET   `{ prompt?: 'select_account' }`  full-page redirect to Microsoft (plain <a>, not an Inertia visit)
 * - `login.emergency`          GET   —                        Auth/Emergency
 * - `login.emergency.store`    POST  body `{ username, password }`
 *
 * Inertia components: `Auth/Login`, `Auth/Emergency`, `Auth/CompanyNotEnabled`.
 */
import type { Company, FormErrors } from '@/types/shared';

export type LoginCompany = Pick<Company, 'id' | 'short_name' | 'logo_url' | 'logo_dark_url'>;

export interface LoginProps {
    /**
     * Enabled companies for the logo row. Note: this key overlaps the guest
     * shared `companies`; send the same list (full `Company` objects) in both.
     */
    companies: LoginCompany[];
    /** Why the last Microsoft sign-in did not finish. */
    error?: 'tenant_not_allowed' | 'microsoft_failed' | null;
}

export interface EmergencyProps {
    /** Set while the account is locked after too many failed attempts. */
    lockout: { locked_until: string } | null;
    /** Attempts left before the lockout; `null` when nothing failed yet. */
    attempts_left: number | null;
    errors?: FormErrors;
}

export interface CompanyNotEnabledProps {
    /** Microsoft account the person signed in with. */
    email: string;
    /** Where to write to IT; hides the link when `null`. */
    support_email: string | null;
}
