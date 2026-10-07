/**
 * Mocks for Errors/Error: every status, with and without shared props.
 */
import type { ErrorPageProps, ErrorStatus } from '@/types/pages/errors';
import { sharedFor, type MockVariant } from './shared';

const LABELS: Record<ErrorStatus, string> = {
    403: 'Sin permiso',
    404: 'No encontrada',
    419: 'Sesión vencida',
    500: 'Error del servidor',
    503: 'Mantenimiento',
};

const STATUSES: ErrorStatus[] = [403, 404, 419, 500, 503];

export const variants: Record<string, MockVariant<ErrorPageProps>> = Object.fromEntries(
    STATUSES.flatMap((status) => [
        [
            `${status}-app`,
            {
                page: 'Errors/Error',
                label: `${status} · ${LABELS[status]} (con sesión)`,
                props: { ...sharedFor(status === 403 ? 'andres' : 'jhon'), status } satisfies ErrorPageProps,
            },
        ],
        [
            `${status}-guest`,
            {
                page: 'Errors/Error',
                label: `${status} · ${LABELS[status]} (sin sesión)`,
                props: { status } satisfies ErrorPageProps,
            },
        ],
    ]),
);
