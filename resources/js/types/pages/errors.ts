/**
 * Error page (`Pages/Errors/Error.tsx`, Inertia component `Errors/Error`),
 * rendered by the exception handler. Shared props may be missing (errors
 * before login or before the middleware ran), so they are all optional.
 *
 * Ziggy routes used:
 * - `home`  GET  "Ir al inicio"
 *
 * Permissions: none.
 */
import type { SharedProps } from '@/types/shared';

export type ErrorStatus = 403 | 404 | 419 | 500 | 503;

export interface ErrorProps {
    status: ErrorStatus;
}

export type ErrorPageProps = Partial<SharedProps> & ErrorProps;
