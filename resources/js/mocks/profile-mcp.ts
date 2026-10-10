/**
 * Mocks for Profile/Mcp (Conector para Claude). Today is sábado 10 oct 2026,
 * 9:24 a. m. in Bogotá. Jhon has two live tokens and a revoked one; the
 * office token's last use is yesterday's rejected write call.
 */
import type { McpActivity, McpCall, McpCreatedToken, McpToken } from '@/types/mcp';
import type { ProfileMcpProps } from '@/types/pages/profile-mcp';
import type { PageProps } from '@/types/shared';
import { sharedFor, type MockVariant } from './shared';

type McpPage = PageProps<ProfileMcpProps>;

export const MCP_NOW = '2026-10-10T09:24:00-05:00';

const SERVER_URL = 'https://[DOMINIO DEL GESTOR]/mcp';

export const MCP_HOLDERS = ['Jhon Castro', 'Leonardo Herrera'];

const LAPTOP: McpToken = {
    id: 12,
    name: 'Claude · portátil',
    scope: 'read_write',
    last4: '7f3a',
    created_at: '2026-10-08T08:30:00-05:00',
    last_used_at: '2026-10-10T09:12:00-05:00',
    last_client: 'Navegador y escritorio',
    expires_at: '2027-01-06',
    revoked_at: null,
};

const OFFICE: McpToken = {
    id: 9,
    name: 'Claude Code · oficina',
    scope: 'read',
    last4: 'c21b',
    created_at: '2026-09-01T10:05:00-05:00',
    last_used_at: '2026-10-09T17:38:00-05:00',
    last_client: 'Terminal de la oficina',
    expires_at: '2026-11-30',
    revoked_at: null,
};

const REVOKED: McpToken = {
    id: 10,
    name: 'Prueba',
    scope: 'read',
    last4: '41d9',
    created_at: '2026-09-12T15:00:00-05:00',
    last_used_at: '2026-09-12T15:04:00-05:00',
    last_client: 'Navegador y escritorio',
    expires_at: '2026-10-12',
    revoked_at: '2026-10-02T11:20:00-05:00',
};

const HOME: McpToken = {
    id: 13,
    name: 'Claude · casa',
    scope: 'read',
    last4: 'b0c4',
    created_at: MCP_NOW,
    last_used_at: null,
    last_client: null,
    expires_at: '2027-01-08',
    revoked_at: null,
};

const TABLET: McpToken = {
    id: 7,
    name: 'Claude · tablet',
    scope: 'read',
    last4: 'e58d',
    created_at: '2026-07-06T11:00:00-05:00',
    last_used_at: '2026-10-03T19:02:00-05:00',
    last_client: 'Aplicación del celular',
    expires_at: '2026-10-04',
    revoked_at: null,
};

const CREATED: McpCreatedToken = {
    id: HOME.id,
    name: HOME.name,
    value: 'gti_mcp_muestra_9d2e41c07ab35f68e2d1b0c4',
    scope: HOME.scope,
    expires_at: HOME.expires_at,
};

const ref = (token: McpToken) => ({ id: token.id, name: token.name });

const CALLS: McpCall[] = [
    { id: 501, tool: 'ver_solicitud', summary: 'GIA-139 · con documentación', at: '2026-10-10T09:12:00-05:00', token: ref(LAPTOP), result: 'ok', reason: null },
    { id: 500, tool: 'buscar_solicitudes', summary: '“cartera” · 4 resultados', at: '2026-10-10T09:10:00-05:00', token: ref(LAPTOP), result: 'ok', reason: null },
    { id: 498, tool: 'agregar_nota', summary: 'P-07 · T4', at: '2026-10-09T17:40:00-05:00', token: ref(LAPTOP), result: 'ok', reason: null },
];

const REJECTED: McpCall = {
    id: 497,
    tool: 'actualizar_tarea',
    summary: 'P-07 · T4 · no se cambió nada',
    at: '2026-10-09T17:38:00-05:00',
    token: ref(OFFICE),
    result: 'rejected',
    reason: 'token de solo lectura',
};

const LAPTOP_ACTIVITY: McpActivity = { token_id: LAPTOP.id, calls: CALLS, rejected_elsewhere: [REJECTED], retention: '[TIEMPO DE RETENCIÓN]' };

const base = {
    ...sharedFor('jhon'),
    server: { url: SERVER_URL, active: true },
    tokens: [LAPTOP, OFFICE, REVOKED],
    connection_test: { status: 'ok', ms: 400, at: '2026-10-10T09:15:00-05:00' },
    created_token: null,
    selected_token: null,
    activity: null,
    mcp_holders: MCP_HOLDERS,
    now: MCP_NOW,
} satisfies McpPage;

export const variants: Record<string, MockVariant<McpPage>> = {
    'mcp-tokens': { page: 'Profile/Mcp', label: 'Conector · tokens de Jhon', props: base },
    'mcp-create': { page: 'Profile/Mcp', label: 'Conector · crear token', props: { ...base, preview: { dialog: 'create' } } },
    'mcp-created': {
        page: 'Profile/Mcp',
        label: 'Conector · token creado (se ve una vez)',
        props: { ...base, tokens: [HOME, LAPTOP, OFFICE, REVOKED], created_token: CREATED },
    },
    'mcp-setup': { page: 'Profile/Mcp', label: 'Conector · cómo agregarlo en Claude', props: { ...base, preview: { anchor: 'setup' } } },
    'mcp-activity': {
        page: 'Profile/Mcp',
        label: 'Conector · actividad de Claude · portátil',
        props: { ...base, selected_token: LAPTOP, activity: LAPTOP_ACTIVITY, preview: { anchor: 'activity' } },
    },
    'mcp-activity-all': {
        page: 'Profile/Mcp',
        label: 'Conector · toda la actividad',
        props: {
            ...base,
            activity: { token_id: null, calls: [...CALLS, REJECTED], rejected_elsewhere: [], retention: '[TIEMPO DE RETENCIÓN]' },
            preview: { anchor: 'activity' },
        },
    },
    'mcp-revoke': {
        page: 'Profile/Mcp',
        label: 'Conector · revocar token',
        props: { ...base, selected_token: LAPTOP, activity: LAPTOP_ACTIVITY, preview: { dialog: 'revoke', revoke_token_id: LAPTOP.id, anchor: 'activity' } },
    },
    'mcp-empty': {
        page: 'Profile/Mcp',
        label: 'Conector · sin tokens',
        props: { ...sharedFor('leonardo'), ...pick(base), tokens: [], connection_test: null },
    },
    'mcp-no-permission': {
        page: 'Profile/Mcp',
        label: 'Conector · sin permiso (Paola)',
        props: { ...sharedFor('paola'), ...pick(base), tokens: [], connection_test: null },
    },
    'mcp-inactive': {
        page: 'Profile/Mcp',
        label: 'Conector · aún no activo en el servidor',
        props: { ...base, server: { url: SERVER_URL, active: false }, connection_test: { status: 'failed', code: 'mcp_not_found', at: '2026-10-10T09:20:00-05:00' } },
    },
    'mcp-test-failed': {
        page: 'Profile/Mcp',
        label: 'Conector · la prueba de conexión falló',
        props: { ...base, connection_test: { status: 'failed', code: 'mcp_timeout', at: '2026-10-10T09:20:00-05:00' } },
    },
    'mcp-expired': {
        page: 'Profile/Mcp',
        label: 'Conector · un token se venció',
        props: { ...base, tokens: [LAPTOP, OFFICE, TABLET, REVOKED] },
    },
};

/** Page props of `base` without the shared ones (to pair them with another user). */
function pick(page: McpPage): ProfileMcpProps {
    const { server, tokens, connection_test, created_token, selected_token, activity, mcp_holders, now } = page;
    return { server, tokens, connection_test, created_token, selected_token, activity, mcp_holders, now };
}
