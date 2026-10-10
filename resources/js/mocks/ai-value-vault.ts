/**
 * Mocks for the value dashboard and the vault (boards B21–B22). Jhon Castro
 * is the only holder of `ai.vault.access`; Leonardo Herrera and Paola Ríos
 * get the Denied page. Every secret here is an obviously fake sample
 * (`sk-muestra-…`). The value figures are sample data for the design.
 * Stage C adds the credential form (C-VaultItemForm): DEEPSEEK_API_KEY ·
 * Gestor TI · producción, last changed 21 jul, next rotation 19 oct.
 */
import type { ValueMetrics } from '@/types/ai';
import type {
    ValueIndexProps,
    ValueProduct,
    VaultCreateProps,
    VaultDeniedProps,
    VaultEditProps,
    VaultIndexProps,
    VaultProjectOption,
} from '@/types/pages/ai-value-vault';
import type { PageProps, Permission, SharedProps } from '@/types/shared';
import { AREAS, NOW_ISO, PEOPLE, PRODUCTS, requestByKey, TODAY, VALUE_METRICS, VAULT_ITEMS, VAULT_LOG, VAULT_REVEALED_SAMPLE } from './ai-fixtures';
import { DERIVADOS, SEGUREX, sharedFor, type MockUserKey, type MockVariant } from './shared';

function without(key: MockUserKey, ...permissions: Permission[]): SharedProps {
    const removed: string[] = permissions;
    const shared = sharedFor(key);
    const user = shared.auth.user;
    return { ...shared, auth: { user: { ...user, permissions: user.permissions.filter((permission) => !removed.includes(permission)) } } };
}

// ---- Value --------------------------------------------------------------------

const roiOf = (name: string) => VALUE_METRICS.roi?.find((row) => row.product === name)?.payback_months ?? null;

const VALUE_PRODUCTS: ValueProduct[] = PRODUCTS.filter((product) => product.hours_saved_per_month !== null)
    .sort((a, b) => (b.hours_saved_per_month ?? 0) - (a.hours_saved_per_month ?? 0))
    .map((product) => ({
        slug: product.slug,
        name: product.name,
        company_id: product.company_id,
        hours_per_month: product.hours_saved_per_month ?? 0,
        payback_months: roiOf(product.name),
    }));

type ValuePage = PageProps<ValueIndexProps>;

const valueJhon: ValuePage = {
    ...sharedFor('jhon'),
    metrics: VALUE_METRICS,
    filters: { period: '6m', company_id: null, area_id: null },
    areas: Object.values(AREAS),
    products: VALUE_PRODUCTS,
    area_notes: [
        'Cartera ahorra más gracias al Visualizador: 4 h a la semana × 5 personas.',
        'Contabilidad apenas empieza: Cuentas por pagar sigue en piloto.',
    ],
    roi_note: 'Cámaras no aparece: aún no está en producción.',
    awaiting_rating: null,
    cost_note: 'Hosting, bases de datos y dominios de 5 productos',
    data_note: 'Cifras de muestra para el diseño. Las reales salen de las calificaciones, del tiempo registrado en el Kanban y de los costos cargados en cada producto.',
    now: NOW_ISO,
};

const NO_COST_METRICS: ValueMetrics = { ...VALUE_METRICS, monthly_cost: null, roi: null, hour_value: null };

const valueNoCosts: ValuePage = {
    ...valueJhon,
    ...without('leonardo', 'ai.costs.view', 'ai.costs.manage'),
    metrics: NO_COST_METRICS,
    products: VALUE_PRODUCTS.map((product) => ({ ...product, payback_months: null })),
    roi_note: null,
    cost_note: null,
};

const GIA_118 = requestByKey('GIA-118');

const valueEmpty: ValuePage = {
    ...valueJhon,
    ...sharedFor('jhon', { currentCompanyId: DERIVADOS.id }),
    metrics: null,
    filters: { period: '6m', company_id: DERIVADOS.id, area_id: null },
    areas: Object.values(AREAS).filter((area) => area.company_id === DERIVADOS.id),
    products: [],
    area_notes: [],
    roi_note: null,
    awaiting_rating: { request_key: GIA_118.key, title: 'Buscador por referencia del Portafolio', requester: PEOPLE.andres },
    data_note: null,
};

// ---- Vault --------------------------------------------------------------------

type VaultPage = PageProps<VaultIndexProps>;

const DEEPSEEK = VAULT_ITEMS[0]!;

const vaultLocked: VaultPage = {
    ...sharedFor('jhon'),
    session: { state: 'locked' },
    items: VAULT_ITEMS,
    selected: null,
    revealed: null,
    access_log: [],
    telegram_alerts: true,
    holder_name: 'Jhon Castro',
    today: TODAY,
    now: NOW_ISO,
};

const OPEN_SESSION = { state: 'open' as const, confirmed_at: '2026-10-07T09:39:00-05:00', expires_at: '2026-10-07T09:49:12-05:00' };

const vaultOpen: VaultPage = {
    ...vaultLocked,
    session: OPEN_SESSION,
    selected: DEEPSEEK,
    revealed: VAULT_REVEALED_SAMPLE,
    access_log: VAULT_LOG,
};

// ---- Vault item form ----------------------------------------------------------------

/** Every product/environment pair that holds credentials, in list order. */
const VAULT_PROJECTS: VaultProjectOption[] = VAULT_ITEMS.reduce<VaultProjectOption[]>((options, item) => {
    if (!options.some((option) => option.product === item.product && option.environment === item.environment)) {
        options.push({ product: item.product, environment: item.environment });
    }
    return options;
}, []);

/** "Se cierra en 8:40" at the mock 9:40 a. m. */
const FORM_SESSION = { state: 'open' as const, confirmed_at: '2026-10-07T09:39:00-05:00', expires_at: '2026-10-07T09:48:40-05:00' };

type VaultEditPage = PageProps<VaultEditProps>;

const vaultEdit: VaultEditPage = {
    ...sharedFor('jhon'),
    session: FORM_SESSION,
    item: DEEPSEEK,
    last_rotated_at: '2026-07-21',
    projects: VAULT_PROJECTS,
    holder_name: 'Jhon Castro',
    telegram_alerts: true,
    today: TODAY,
    now: NOW_ISO,
};

type VaultCreatePage = PageProps<VaultCreateProps>;

const vaultCreate: VaultCreatePage = {
    ...sharedFor('jhon'),
    session: FORM_SESSION,
    projects: VAULT_PROJECTS,
    defaults: null,
    holder_name: 'Jhon Castro',
    telegram_alerts: true,
    today: TODAY,
    now: NOW_ISO,
};

type DeniedPage = PageProps<VaultDeniedProps>;

const deniedLeonardo: DeniedPage = {
    ...sharedFor('leonardo'),
    holder_name: 'Jhon Castro',
    attempt: { person: PEOPLE.leonardo, at: NOW_ISO },
};

// ---- variants -----------------------------------------------------------------

export const variants: Record<
    string,
    MockVariant<ValuePage> | MockVariant<VaultPage> | MockVariant<DeniedPage> | MockVariant<VaultEditPage> | MockVariant<VaultCreatePage>
> = {
    // Value
    value: { page: 'Ai/Value/Index', label: 'Valor · últimos 6 meses (Jhon, Todas)', props: valueJhon },
    'value-segurex': {
        page: 'Ai/Value/Index',
        label: 'Valor · solo Segurex',
        props: {
            ...valueJhon,
            ...sharedFor('jhon', { currentCompanyId: SEGUREX.id }),
            filters: { period: '6m', company_id: SEGUREX.id, area_id: null },
            areas: Object.values(AREAS).filter((area) => area.company_id === SEGUREX.id),
            products: VALUE_PRODUCTS.filter((product) => product.company_id === SEGUREX.id),
        },
    },
    'value-no-costs': { page: 'Ai/Value/Index', label: 'Valor · sin permiso de costos (Leonardo)', props: valueNoCosts },
    'value-empty': { page: 'Ai/Value/Index', label: 'Valor · aún sin calificaciones (Derivados)', props: valueEmpty },

    // Vault
    'vault-locked': { page: 'Ai/Vault/Index', label: 'Bóveda · cerrada (Jhon)', props: vaultLocked },
    'vault-open': { page: 'Ai/Vault/Index', label: 'Bóveda · abierta, valor visible 30 s', props: vaultOpen },
    'vault-open-hidden': {
        page: 'Ai/Vault/Index',
        label: 'Bóveda · abierta, valor oculto',
        props: { ...vaultOpen, revealed: null },
    },
    'vault-open-none': {
        page: 'Ai/Vault/Index',
        label: 'Bóveda · abierta, sin credencial elegida',
        props: { ...vaultOpen, selected: null, revealed: null, access_log: [] },
    },
    'vault-edit': { page: 'Ai/Vault/Edit', label: 'Bóveda · editar credencial (DEEPSEEK_API_KEY)', props: vaultEdit },
    'vault-edit-delete-typed': {
        page: 'Ai/Vault/Edit',
        label: 'Bóveda · editar, nombre escrito para eliminar',
        props: { ...vaultEdit, preview_delete_confirm: 'DEEPSEEK_API_KEY' },
    },
    'vault-edit-errors': {
        page: 'Ai/Vault/Edit',
        label: 'Bóveda · editar con errores',
        props: { ...vaultEdit, errors: { name: 'Ya hay una credencial con ese nombre en Gestor TI · producción.' } },
    },
    'vault-create': { page: 'Ai/Vault/Create', label: 'Bóveda · nueva credencial', props: vaultCreate },
    'vault-denied': { page: 'Ai/Vault/Denied', label: 'Bóveda · acceso negado (Leonardo)', props: deniedLeonardo },
    'vault-denied-requester': {
        page: 'Ai/Vault/Denied',
        label: 'Bóveda · acceso negado (Paola, solicitante)',
        props: { ...deniedLeonardo, ...sharedFor('paola'), attempt: { person: PEOPLE.paola, at: '2026-10-06T16:12:00-05:00' } },
    },
};
