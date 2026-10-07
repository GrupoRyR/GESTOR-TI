/**
 * Design-system sheet for the backend-free preview: every UI component in a
 * few states, plus the three layouts with inline sample data. Shown as the
 * first gallery entry ("Sistema de diseño") inside the frame.
 */
import { useState, type ReactNode } from 'react';
import logoDerivadosDark from '../resources/brand/logo-derivados-dark.svg';
import logoDerivados from '../resources/brand/logo-derivados.svg';
import logoSegurexDark from '../resources/brand/logo-segurex-dark.svg';
import logoSegurex from '../resources/brand/logo-segurex.svg';
import {
    Alert,
    Avatar,
    BudgetBar,
    Button,
    Checkbox,
    Chip,
    ColorField,
    CompanyLogo,
    CompanyMark,
    DataTable,
    EmptyState,
    ErrorState,
    FileDrop,
    FlashMessages,
    Icon,
    ICON_NAMES,
    IconButton,
    NoPermission,
    Pagination,
    Panel,
    PhaseRail,
    SegmentedControl,
    Select,
    Semaphore,
    SizeTag,
    SkeletonRows,
    StatusChip,
    Switch,
    Tabs,
    TextField,
    type DataTableColumn,
    type Size,
} from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { AppLayout } from '@/Layouts/AppLayout';
import { GuestLayout } from '@/Layouts/GuestLayout';
import { REQUEST_STATUSES, type RequestStatusKey } from '@/lib/status';
import type { Company, SharedProps } from '@/types/shared';

// ---- sample data ----------------------------------------------------------

const SEGUREX: Company = {
    id: 1,
    slug: 'segurex',
    name: 'Segurex',
    short_name: 'Segurex',
    logo_url: logoSegurex,
    logo_dark_url: logoSegurexDark,
    colors: { primary: '#FF671F', accent: '#FF8A4C', dark: '#2B2B2B' },
};

const DERIVADOS: Company = {
    id: 2,
    slug: 'derivados',
    name: 'Soluciones y Derivados de Colombia',
    short_name: 'Derivados',
    logo_url: logoDerivados,
    logo_dark_url: logoDerivadosDark,
    colors: { primary: '#183F94', accent: '#3C6BD1', dark: '#03256C' },
};

const ANDINA: Company = {
    id: 3,
    slug: 'andina',
    name: 'Andina',
    short_name: 'Andina',
    logo_url: null,
    logo_dark_url: null,
    colors: { primary: '#0F8A7E', accent: '#3FBFAE', dark: '#0A4F48' },
};

export const SAMPLE_SHARED: SharedProps = {
    auth: {
        user: {
            id: 1,
            name: 'Jhon Castro',
            email: 'jhon.castro@segurex.com.co',
            avatar_url: null,
            company_id: 1,
            roles: ['Líder IA', 'Súper Admin'],
            permissions: ['companies.view_all', 'ai.manage', 'assistant.use'],
        },
    },
    companies: [SEGUREX, DERIVADOS],
    current_company_id: null,
    flash: { success: 'Cambios guardados.' },
    notifications_unread: 4,
    modules: [
        { key: 'home', name: 'Inicio', icon: 'home', route: 'home', active: true },
        { key: 'ai', name: 'Gestor IA', icon: 'ai', route: 'ai.index', active: true },
        { key: 'assistant', name: 'Asistente', icon: 'assistant', route: 'assistant.index', active: true },
        { key: 'employees', name: 'Colaboradores', icon: 'employees', route: 'employees.index', active: true },
        { key: 'admin', name: 'Administración', icon: 'admin', route: 'admin.companies.index', active: true },
        { key: 'monitoring', name: 'Monitoreo', icon: 'monitoring', route: 'monitoring.index', active: false },
        { key: 'tickets', name: 'Tickets', icon: 'tickets', route: 'tickets.index', active: false },
    ],
};

interface SampleRequest {
    id: number;
    key: string;
    title: string;
    requester: string;
    area: string;
    company: Company;
    status: RequestStatusKey;
    size: Size | null;
    priority: number | null;
}

const REQUESTS: SampleRequest[] = [
    {
        id: 148,
        key: 'GIA-148',
        title: 'Toma Pedidos SAP: avisar al asesor cuando el pedido quede bloqueado por cartera',
        requester: 'Paola Ríos · 6 h/sem × 8 personas · +3',
        area: 'Ventas',
        company: SEGUREX,
        status: 'received',
        size: null,
        priority: null,
    },
    {
        id: 146,
        key: 'GIA-146',
        title: 'Portafolio de productos: exportar fichas técnicas en PDF',
        requester: 'Andrés Méndez · 3 h/sem × 4 personas',
        area: 'Compras',
        company: DERIVADOS,
        status: 'pending_approval',
        size: 'M',
        priority: 68,
    },
    {
        id: 139,
        key: 'GIA-139',
        title: 'Visualizador de cartera: filtro por asesor y zona',
        requester: 'Gerencia de cartera · 4 h/sem × 5 personas · +2',
        area: 'Cartera',
        company: SEGUREX,
        status: 'in_development',
        size: 'L',
        priority: 82,
    },
];

const COLUMNS: DataTableColumn<SampleRequest>[] = [
    { key: 'company', header: 'Empresa', hideHeader: true, width: '28px', render: (row) => <CompanyMark company={row.company} /> },
    { key: 'key', header: 'Clave', width: '84px', render: (row) => <span className="tabular text-[13px] font-bold">{row.key}</span> },
    {
        key: 'title',
        header: 'Solicitud',
        rowLink: true,
        render: (row) => (
            <span className="flex min-w-0 flex-col">
                <span className="truncate font-semibold">{row.title}</span>
                <span className="truncate text-xs text-fg-muted">{row.requester}</span>
            </span>
        ),
    },
    { key: 'area', header: 'Área', width: '110px', render: (row) => row.area },
    { key: 'phase', header: 'Fase', width: '62px', render: (row) => <PhaseRail status={row.status} /> },
    { key: 'status', header: 'Estado', width: '200px', render: (row) => <StatusChip status={row.status} size="sm" /> },
    {
        key: 'size',
        header: 'Talla',
        width: '60px',
        render: (row) => (row.size ? <span className="text-[13px] font-bold">{row.size}</span> : <span className="text-fg-muted">—</span>),
    },
    {
        key: 'priority',
        header: 'Prior.',
        width: '52px',
        align: 'end',
        render: (row) => (row.priority ? <span className="tabular font-bold">{row.priority}</span> : <span className="text-fg-muted">—</span>),
    },
];

// ---- helpers --------------------------------------------------------------

function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="font-display text-2xl font-bold">{title}</h2>
                {description ? <p className="max-w-[70ch] text-sm text-fg-soft">{description}</p> : null}
            </div>
            {children}
        </section>
    );
}

function Row({ children }: { children: ReactNode }) {
    return <div className="flex flex-wrap items-center gap-3">{children}</div>;
}

// ---- component sheet ------------------------------------------------------

export function DesignSystem() {
    const [company, setCompany] = useState('all');
    const [notify, setNotify] = useState(true);
    const [color, setColor] = useState('#FF671F');
    const allStatuses = Object.keys(REQUEST_STATUSES) as RequestStatusKey[];

    return (
        <div className="mx-auto flex max-w-[1200px] flex-col gap-12 px-4 py-8 md:px-8">
            <header className="flex flex-col gap-2">
                <h1 className="font-display text-4xl font-bold">Sistema de diseño · Etapa A</h1>
                <p className="max-w-[70ch] text-fg-soft">
                    Componentes base del Gestor TI sobre el sistema del Portal. Cambia el tema arriba para revisarlos en claro y oscuro.
                </p>
            </header>

            <Section title="Botones" description="Altos de 44, 48 y 56 px. El primario usa el color de la marca activa.">
                <Row>
                    <Button>Guardar evaluación</Button>
                    <Button variant="secondary">Pedir información</Button>
                    <Button variant="quiet">Unir con otra</Button>
                    <Button variant="dark" icon="refresh">
                        Volver a intentar
                    </Button>
                </Row>
                <Row>
                    <Button variant="success" size="lg" icon="check">
                        Aprobar
                    </Button>
                    <Button variant="danger" size="lg" icon="x">
                        Rechazar
                    </Button>
                    <Button size="xl" icon="plus">
                        Pedir un desarrollo o una mejora
                    </Button>
                </Row>
                <Row>
                    <Button loading>Guardando…</Button>
                    <Button variant="secondary" disabled>
                        No disponible
                    </Button>
                    <Button variant="secondary" href="#solicitudes" icon="external" iconPosition="end">
                        Enlace con Inertia
                    </Button>
                    <IconButton icon="bell" label="Notificaciones, 4 sin leer" badge={4} />
                    <IconButton icon="edit" label="Editar" />
                    <IconButton icon="more" label="Más acciones" />
                </Row>
            </Section>

            <Section title="Estados de una solicitud" description="Siempre ícono + texto; el color solo agrupa la familia.">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {allStatuses.map((status) => (
                        <div key={status} className="flex items-center justify-between gap-3 rounded-control border border-line bg-surface px-3 py-2.5">
                            <StatusChip status={status} />
                            <PhaseRail status={status} />
                        </div>
                    ))}
                </div>
                <div className="grid gap-4 md:grid-cols-3">
                    <Panel title="Planificada">
                        <PhaseRail status="planned" variant="full" />
                    </Panel>
                    <Panel title="En pruebas">
                        <PhaseRail status="in_testing" variant="full" />
                    </Panel>
                    <Panel title="Rechazada">
                        <PhaseRail status="rejected" variant="full" />
                    </Panel>
                </div>
                <Row>
                    <Chip tone="gray">Gris</Chip>
                    <Chip tone="amber" dot>
                        Ámbar
                    </Chip>
                    <Chip tone="blue" icon="code">
                        Azul
                    </Chip>
                    <Chip tone="green" dot>
                        Verde
                    </Chip>
                    <Chip tone="slate">Pizarra</Chip>
                    <Chip tone="red" icon="blocked">
                        Rojo
                    </Chip>
                    <Chip tone="outline">Contorno</Chip>
                </Row>
            </Section>

            <Section title="Semáforo, talla y presupuesto">
                <div className="grid gap-4 md:grid-cols-2">
                    <Panel title="Semáforo y talla">
                        <Row>
                            <Semaphore level="ok" />
                            <Semaphore level="attention" />
                            <Semaphore level="late">Vencida hace 1 día</Semaphore>
                        </Row>
                        <Row>
                            {(['S', 'M', 'L', 'XL'] as Size[]).map((size) => (
                                <SizeTag key={size} size={size} />
                            ))}
                        </Row>
                    </Panel>
                    <Panel title="Estimado contra real">
                        <BudgetBar label="Horas" value={92} max={120} valueText="92 de 120 h" message="Quedan 28 h" />
                        <BudgetBar
                            label="Costo"
                            value={14_200_000}
                            max={16_000_000}
                            valueText="$ 14.200.000 de $ 16.000.000"
                            message="Pasó el 80 %: aviso enviado a los aprobadores"
                        />
                        <BudgetBar label="Fecha" value={44} max={30} valueText="14 nov · estimada 31 oct" message="14 días después de lo aprobado" />
                    </Panel>
                </div>
            </Section>

            <Section title="Empresas y personas" description="Cada fila muestra los colores de su propia empresa, también en la vista Todas.">
                <Row>
                    <CompanyMark company={SEGUREX} />
                    <CompanyMark company={DERIVADOS} />
                    <CompanyMark company={ANDINA} />
                    <CompanyMark company={SEGUREX} size={40} variant="solid" />
                    <CompanyMark company={DERIVADOS} size={40} variant="solid" />
                    <CompanyMark company={ANDINA} size={40} variant="solid" />
                </Row>
                <Row>
                    <CompanyLogo company={SEGUREX} height={22} />
                    <CompanyLogo company={DERIVADOS} height={40} />
                    <CompanyLogo company={ANDINA} height={32} />
                </Row>
                <Row>
                    <Avatar name="Jhon Castro" size="sm" />
                    <Avatar name="Paola Ríos" size="md" />
                    <Avatar name="Leonardo Herrera" size="lg" tone="strong" />
                </Row>
            </Section>

            <Section title="Avisos">
                <div className="flex flex-col gap-3">
                    <Alert tone="info" title="Sincronización programada">
                        Los colaboradores se traen de Microsoft todos los días a las 6:00 a. m.
                    </Alert>
                    <Alert tone="warning" title="Credencial por vencer">
                        DEEPSEEK_API_KEY se debe rotar en 12 días.
                    </Alert>
                    <Alert tone="danger" title="La sincronización con Microsoft falló">
                        Falta el permiso User.Read.All en el inquilino de Derivados.
                    </Alert>
                    <FlashMessages flash={{ success: 'Empresa creada.', error: 'No se pudo subir el logo: pesa más de 1 MB.' }} />
                </div>
            </Section>

            <Section title="Formularios">
                <div className="grid gap-4 md:grid-cols-2">
                    <Panel title="Campos">
                        <TextField label="Nombre de la empresa" placeholder="Ej. Segurex" hint="Como aparece en los documentos." />
                        <TextField label="Dominio de correo" defaultValue="segurex" error="Escribe el dominio completo, por ejemplo segurex.com.co." />
                        <TextField label="Horas por semana" size="lg" inputMode="numeric" defaultValue="6" />
                        <Select
                            label="Área"
                            placeholder="Selecciona un área"
                            options={[
                                { value: 'ventas', label: 'Ventas' },
                                { value: 'cartera', label: 'Cartera' },
                                { value: 'compras', label: 'Compras' },
                            ]}
                        />
                        <ColorField label="Color principal" value={color} onChange={setColor} hint="Se ajusta solo para que el texto se lea." />
                    </Panel>
                    <Panel title="Opciones y archivos">
                        <Checkbox label="Puede ver todas las empresas" description="Da acceso a la vista Todas." defaultChecked />
                        <Checkbox label="Recibir resumen semanal" />
                        <Switch checked={notify} onChange={setNotify} label="Avisos por Telegram" description="Te escribimos cuando una solicitud cambie de estado." />
                        <Switch checked={false} onChange={() => undefined} label="Monitoreo" description="Disponible cuando se active el módulo." disabled />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FileDrop label="Logo en claro" accept="image/svg+xml,image/png" previewUrl={logoSegurex} hint="SVG o PNG, máx. 1 MB." />
                            <FileDrop label="Logo en oscuro" accept="image/svg+xml,image/png" surface="dark" previewUrl={logoSegurexDark} />
                        </div>
                    </Panel>
                </div>
            </Section>

            <Section title="Navegación">
                <Panel padded={false}>
                    <Tabs
                        label="Ejemplo de pestañas"
                        current="users"
                        className="border-b border-line px-3"
                        items={[
                            { key: 'companies', label: 'Empresas', href: '#empresas' },
                            { key: 'areas', label: 'Áreas', href: '#areas' },
                            { key: 'users', label: 'Usuarios', href: '#usuarios', count: 214 },
                            { key: 'roles', label: 'Roles y permisos', href: '#roles' },
                            { key: 'integrations', label: 'Integraciones', href: '#integraciones' },
                            { key: 'audit', label: 'Auditoría', href: '#auditoria' },
                        ]}
                    />
                    <div className="p-5">
                        <SegmentedControl
                            label="Empresa"
                            value={company}
                            onChange={setCompany}
                            options={[
                                { value: '1', label: 'Segurex' },
                                { value: '2', label: 'Derivados' },
                                { value: 'all', label: 'Todas' },
                            ]}
                        />
                    </div>
                </Panel>
            </Section>

            <Section title="Tablas" description="Fila completa como enlace; en pantallas angostas la tabla se desplaza de lado.">
                <Panel
                    padded={false}
                    title='Bandeja de solicitudes · vista "Todas"'
                    actions={
                        <Button variant="secondary" icon="filter">
                            Área: todas
                        </Button>
                    }
                >
                    <DataTable
                        label="Solicitudes"
                        columns={COLUMNS}
                        rows={REQUESTS}
                        rowKey={(row) => row.id}
                        rowHref={(row) => `#${row.key}`}
                        minWidth={900}
                    />
                    <Pagination
                        paginated={{
                            meta: { current_page: 1, last_page: 27, per_page: 8, total: 214 },
                            links: [
                                { url: null, label: 'Anterior', active: false },
                                { url: '#pagina-1', label: '1', active: true },
                                { url: '#pagina-2', label: 'Siguiente', active: false },
                            ],
                        }}
                    />
                </Panel>
                <Panel padded={false} title="Tabla vacía">
                    <DataTable
                        label="Solicitudes vacías"
                        columns={COLUMNS}
                        rows={[]}
                        rowKey={(row) => row.id}
                        minWidth={900}
                        empty={<p className="text-sm text-fg-muted">No hay solicitudes con estos filtros.</p>}
                    />
                </Panel>
            </Section>

            <Section title="Estados de pantalla">
                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel padded={false} title="Cargando" description="Esqueleto con la forma real de la tabla">
                        <SkeletonRows />
                    </Panel>
                    <EmptyState
                        icon="employees"
                        title="Todavía no hay colaboradores de Derivados"
                        description="Se cargan desde Microsoft una vez al día. Puedes traerlos ahora; toma menos de un minuto."
                        actions={
                            <>
                                <Button>Sincronizar ahora</Button>
                                <Button variant="secondary">Agregar a mano</Button>
                            </>
                        }
                    />
                    <ErrorState
                        title="La sincronización con Microsoft falló"
                        description={
                            <>
                                El inquilino de Derivados no ha dado el permiso <b className="text-fg">User.Read.All</b>. Un administrador de
                                Microsoft de Derivados debe aprobarlo; después vuelve a intentar.
                            </>
                        }
                        actions={
                            <>
                                <Button variant="dark">Volver a intentar</Button>
                                <Button variant="secondary" href="#integraciones">
                                    Ver la integración
                                </Button>
                            </>
                        }
                        footnote="Último intento: hoy 6:00 a. m. · código graph_403"
                    />
                    <NoPermission
                        title="Esta sección es solo para Administración"
                        description="Tu rol es Usuario final. Si necesitas entrar, pídeselo a Jhon Castro o a Leonardo Herrera."
                    />
                </div>
            </Section>

            <Section title="Íconos" description="Trazo de 2 px sobre una grilla de 24.">
                <ul className="grid grid-cols-[repeat(auto-fill,minmax(112px,1fr))] gap-2">
                    {ICON_NAMES.map((name) => (
                        <li key={name} className="flex flex-col items-center gap-2 rounded-control border border-line bg-surface px-2 py-3 text-xs text-fg-muted">
                            <Icon name={name} size={22} className="text-fg" />
                            {name}
                        </li>
                    ))}
                </ul>
            </Section>
        </div>
    );
}

// ---- layout demos ---------------------------------------------------------

function DemoContent({ heading }: { heading: string }) {
    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-1">
                <h1 className="font-display text-[28px] font-bold md:text-[32px]">{heading}</h1>
                <span className="text-sm text-fg-muted">7 cosas requieren atención</span>
            </div>
            <Panel padded={false} title="Solicitudes recientes">
                <DataTable label="Solicitudes recientes" columns={COLUMNS} rows={REQUESTS} rowKey={(row) => row.id} rowHref={(row) => `#${row.key}`} minWidth={900} />
            </Panel>
        </div>
    );
}

export function AppLayoutDemo() {
    return (
        <AppLayout shared={SAMPLE_SHARED} title="Inicio · Gestor TI" active="home" badges={{ ai: 5 }}>
            <DemoContent heading="Buenos días, Jhon" />
        </AppLayout>
    );
}

export function AdminLayoutDemo() {
    return (
        <AdminLayout shared={{ ...SAMPLE_SHARED, current_company_id: 2, flash: {} }} title="Usuarios · Administración" tab="users" badges={{ ai: 5 }}>
            <DemoContent heading="Usuarios" />
        </AdminLayout>
    );
}

export function GuestLayoutDemo() {
    return (
        <GuestLayout
            title="Entrar · Gestor TI"
            footer={
                <a href="#emergencia" className="underline underline-offset-[3px]">
                    Cuenta de emergencia del administrador
                </a>
            }
        >
            <div className="flex flex-col gap-3">
                <h1 className="font-display text-[34px] leading-[1.06] font-bold lg:text-[44px]">Entra con tu cuenta de trabajo</h1>
                <p className="text-base leading-normal text-fg-soft">
                    Es la misma de tu correo de Segurex o de Derivados. No necesitas otra contraseña.
                </p>
            </div>
            <Button variant="dark" size="xl" fullWidth>
                Entrar con Microsoft
            </Button>
            <div className="flex items-center gap-8">
                <CompanyLogo company={SEGUREX} height={22} />
                <CompanyLogo company={DERIVADOS} height={40} />
            </div>
        </GuestLayout>
    );
}
