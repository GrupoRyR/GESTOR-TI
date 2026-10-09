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
import { KANBAN_COLUMNS, KanbanCard, KanbanColumn, SkeletonBoard, TimerButton, VaultZone } from '@/Components/ai';
import {
    Alert,
    Avatar,
    BarList,
    BudgetBar,
    Button,
    Checkbox,
    Chip,
    ChipSelect,
    ChoiceCardGroup,
    ColorField,
    CompanyLogo,
    CompanyMark,
    CountdownRing,
    DataTable,
    Dialog,
    DiffView,
    Drawer,
    EmptyState,
    ErrorState,
    FileDrop,
    FlashMessages,
    Icon,
    ICON_NAMES,
    IconButton,
    LineChart,
    MarkdownEditor,
    MarkdownPreview,
    NoPermission,
    NumberStepper,
    Pagination,
    Panel,
    PhaseRail,
    RatingScale,
    SegmentedControl,
    Select,
    Semaphore,
    SizeTag,
    SkeletonRows,
    SortableList,
    StatTile,
    StatusChip,
    Switch,
    Tabs,
    TextField,
    Timeline,
    type ChipOption,
    type ChoiceOption,
    type DataTableColumn,
    type IconName,
    type Rating,
    type Size,
} from '@/Components/ui';
import { AdminLayout } from '@/Layouts/AdminLayout';
import { AppLayout } from '@/Layouts/AppLayout';
import { gestorTabsFor } from '@/Layouts/GestorLayout';
import { GuestLayout } from '@/Layouts/GuestLayout';
import { formatDate } from '@/lib/format';
import { REQUEST_STATUSES, type RequestStatusKey } from '@/lib/status';
import {
    backlog,
    DIFF_MANUAL_6_7,
    HOURS_CURVE_P07,
    MANUAL_V7,
    NOW_ISO,
    REJECTION_REASON,
    taskByKey,
    tasksOf,
    TIMELINE_GIA_139,
    VALUE_METRICS,
    VAULT_ITEMS,
} from '@/mocks/ai-fixtures';
import { sharedFor } from '@/mocks/shared';
import type { KanbanColumnKey, RequestSummary, Task, TimelineEvent } from '@/types/ai';
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
                <h1 className="font-display text-4xl font-bold">Sistema de diseño · Etapas A y B</h1>
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
                    <Panel
                        title="Kanban cargando"
                        description="Esqueleto con las columnas reales; los nombres aparecen de una vez (B-States)"
                        className="lg:col-span-2"
                    >
                        <SkeletonBoard title="P-07 · Visualizador de cartera" />
                    </Panel>
                </div>
            </Section>

            <GestorSection />

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

// ---- Gestor IA (Stage B) --------------------------------------------------

const TIMELINE_ICONS: Record<TimelineEvent['kind'], IconName> = {
    status: 'code',
    question: 'question',
    answer: 'message',
    approval: 'check-circle',
    rejection: 'x-circle',
    comment: 'message',
    notification: 'bell',
    estimate: 'clock',
    merge: 'link',
    priority: 'trend',
};

const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const MONTH_NAMES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const DAY_MS = 86_400_000;
const dayIndex = (date: string) => Math.round(Date.parse(`${date}T00:00:00Z`) / DAY_MS);
const dayLabel = (index: number) => {
    const date = new Date(index * DAY_MS);
    return `${date.getUTCDate()} ${MONTHS[date.getUTCMonth()]}`;
};
const hoursText = (value: number) => `${Math.round(value)} h`;

type RequestTypeKey = 'new' | 'improvement' | 'bug' | 'idea';

const REQUEST_TYPES: ChoiceOption<RequestTypeKey>[] = [
    { value: 'new', title: 'Algo nuevo', description: 'Una app, un reporte o una automatización que no existe.', icon: 'plus' },
    {
        value: 'improvement',
        title: 'Mejorar una app que ya usamos',
        description: 'Agregar o cambiar algo en una app que ya funciona.',
        icon: 'edit',
        detail: (
            <span>
                App <b>Portafolio de productos</b> · v1.8.2
            </span>
        ),
    },
    { value: 'bug', title: 'Algo no funciona en una app', description: 'Un error, algo lento o un dato que sale mal.', icon: 'alert-triangle' },
    { value: 'idea', title: 'Tengo una idea o una pregunta', description: 'No sé si se puede. Quiero que TI lo mire.', icon: 'question' },
];

const SYSTEMS: ChipOption[] = [
    { value: 'sap_b1', label: 'SAP Business One' },
    { value: 'excel', label: 'Excel' },
    { value: 'email', label: 'Correo' },
    { value: 'toma_pedidos', label: 'Toma Pedidos SAP' },
    { value: 'portafolio', label: 'Portafolio de productos' },
    { value: 'other', label: 'Otra app…', icon: 'plus' },
];

function GestorSection() {
    const [type, setType] = useState<RequestTypeKey | null>('improvement');
    const [hours, setHours] = useState<number | null>(6);
    const [people, setPeople] = useState<number | null>(8);
    const [systems, setSystems] = useState<string[]>(['sap_b1', 'excel', 'email', 'portafolio']);
    const [urgency, setUrgency] = useState<string | null>('month');
    const [solved, setSolved] = useState<Rating | null>(4);
    const [dialog, setDialog] = useState(false);
    const [drawer, setDrawer] = useState(false);
    const [tasks, setTasks] = useState<Task[]>(() => tasksOf('P-07'));
    const [backlogRows, setBacklogRows] = useState<RequestSummary[]>(() => backlog());
    const [markdown, setMarkdown] = useState(MANUAL_V7);
    const [dragKey, setDragKey] = useState<string | null>(null);
    const [dropColumn, setDropColumn] = useState<KanbanColumnKey | null>(null);
    const team = hours !== null && people !== null ? hours * people : null;
    const runningT4 = taskByKey('P-07', 'T4').timer?.started_at ?? NOW_ISO;

    const moveTask = (task: Task, column: KanbanColumnKey) =>
        setTasks((current) => current.map((item) => (item.key === task.key ? { ...item, column } : item)));
    // One running timer per person: starting one stops the others.
    const setTimer = (task: Task, running: boolean) =>
        setTasks((current) =>
            current.map((item) => {
                if (item.key === task.key) return { ...item, timer: running ? { running: true, started_at: new Date().toISOString(), person_id: 1 } : null };
                return running && item.timer?.running ? { ...item, timer: null } : item;
            }),
        );

    const inProgress = tasks.filter((task) => task.column === 'in_progress').length;
    const dragged = tasks.find((task) => task.key === dragKey);

    return (
        <Section
            title="Gestor IA · componentes"
            description="Piezas nuevas de la Etapa B. Los datos salen de mocks/ai-fixtures.ts, los mismos que usan las páginas del Gestor."
        >
            <Panel title="Pestañas del Gestor IA" description="Jhon ve la Bóveda; Leonardo no tiene ai.vault.access y no la ve. Los costos nunca son pestaña.">
                <Tabs label="Pestañas de Jhon" items={gestorTabsFor(sharedFor('jhon'), { inbox: 5 })} current="inbox" className="rounded-control border border-line px-2" />
                <Tabs label="Pestañas de Leonardo" items={gestorTabsFor(sharedFor('leonardo'), { inbox: 5 })} current="projects" className="rounded-control border border-line px-2" />
            </Panel>

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel title="Línea de tiempo de la solicitud">
                    <Timeline
                        label="Línea de tiempo de GIA-139"
                        items={TIMELINE_GIA_139.map((event) => ({
                            id: event.id,
                            icon: event.kind === 'status' ? (event.family === 'gray' ? 'inbox' : 'code') : TIMELINE_ICONS[event.kind],
                            family: event.family,
                            title: event.title,
                            quote: event.quote,
                            meta: [event.at === NOW_ISO ? 'Hoy, 9:40 a. m.' : formatDate(event.at), event.detail ?? event.actor].filter(Boolean).join(' · '),
                        }))}
                    />
                </Panel>
                <Panel title="Paso 3 de 7 · Cuánto tiempo">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <NumberStepper label="Horas por semana" hint="Lo que gasta cada persona" value={hours} onChange={setHours} min={0.5} max={60} step={0.5} unit="h" />
                        <NumberStepper label="Cuántas personas" hint="Las que hacen esa tarea" value={people} onChange={setPeople} min={1} max={500} unit="personas" />
                    </div>
                    <p className="text-sm text-fg-soft" aria-live="polite">
                        {team !== null ? (
                            <>
                                Son <b className="tabular">{team} horas</b> a la semana del equipo.
                            </>
                        ) : (
                            'Escribe las horas y las personas.'
                        )}
                    </p>
                    <RatingScale legend="¿Resolvió tu necesidad?" ends={['Nada', 'Por completo']} value={solved} onChange={setSolved} />
                </Panel>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel title="Tarjetas de opción">
                    <ChoiceCardGroup legend="Tipo de solicitud" options={REQUEST_TYPES} value={type} onChange={setType} />
                </Panel>
                <Panel title="Chips de selección">
                    <span className="text-[13px] font-semibold">Sistemas · {systems.length} marcados</span>
                    <ChipSelect label="Sistemas que usa" multiple options={SYSTEMS} value={systems} onChange={setSystems} />
                    <span className="text-[13px] font-semibold">Urgencia</span>
                    <ChipSelect
                        label="Urgencia"
                        options={[
                            { value: 'low', label: 'Puede esperar' },
                            { value: 'quarter', label: 'Este trimestre' },
                            { value: 'month', label: 'Este mes' },
                            { value: 'urgent', label: 'Urgente' },
                        ]}
                        value={urgency}
                        onChange={setUrgency}
                    />
                    <Row>
                        <Button variant="secondary" onClick={() => setDialog(true)}>
                            Abrir diálogo
                        </Button>
                        <Button variant="secondary" icon="list" onClick={() => setDrawer(true)}>
                            Abrir panel lateral
                        </Button>
                    </Row>
                </Panel>
            </div>

            <Panel title="Tablero" description="Arrastra una tarjeta o usa Mover a… con teclado. La columna En progreso tiene límite de 3.">
                <div className="-mx-1 overflow-x-auto px-1 pb-1">
                    <div className="grid min-w-[1080px] grid-cols-5 items-start gap-3">
                        {KANBAN_COLUMNS.map((column) => {
                            const cards = tasks.filter((task) => task.column === column.key);
                            const wip = column.key === 'in_progress' ? { current: inProgress, limit: 3 } : undefined;
                            const hovering = dropColumn === column.key && dragged !== undefined && dragged.column !== column.key;
                            return (
                                <KanbanColumn
                                    key={column.key}
                                    column={column.key}
                                    count={cards.length}
                                    wip={wip}
                                    dropHint={hovering ? (wip ? `Quedarían ${wip.current + 1} de ${wip.limit}` : `Pasa a ${column.label}`) : null}
                                    onDragOver={(event) => {
                                        if (dragKey === null) return;
                                        event.preventDefault();
                                        setDropColumn(column.key);
                                    }}
                                    onDrop={(event) => {
                                        event.preventDefault();
                                        if (dragged) moveTask(dragged, column.key);
                                        setDragKey(null);
                                        setDropColumn(null);
                                    }}
                                    onAddTask={() => undefined}
                                >
                                    {cards.map((task) => (
                                        <KanbanCard
                                            key={task.key}
                                            task={task}
                                            clockStartAt={task.timer?.started_at === runningT4 ? NOW_ISO : undefined}
                                            onOpen={() => setDrawer(true)}
                                            onMove={moveTask}
                                            onTimerStart={(item) => setTimer(item, true)}
                                            onTimerStop={(item) => setTimer(item, false)}
                                            onDragStart={(event, item) => {
                                                event.dataTransfer.effectAllowed = 'move';
                                                event.dataTransfer.setData('text/plain', item.key);
                                                setDragKey(item.key);
                                            }}
                                            onDragEnd={() => {
                                                setDragKey(null);
                                                setDropColumn(null);
                                            }}
                                            dragging={dragKey === task.key}
                                        />
                                    ))}
                                </KanbanColumn>
                            );
                        })}
                    </div>
                </div>
            </Panel>

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel padded={false} title="Lista ordenable" description="Arrastra la manija o usa Subir y Bajar.">
                    <SortableList
                        label="Backlog priorizado"
                        items={backlogRows}
                        getKey={(row) => row.key}
                        getLabel={(row) => row.key}
                        onReorder={(rows) => setBacklogRows(rows)}
                        renderItem={(row) => (
                            <span className="flex min-w-0 flex-col">
                                <span className="text-xs font-bold text-fg-muted">{row.key}</span>
                                <span className="truncate text-sm font-semibold">{row.title}</span>
                            </span>
                        )}
                    />
                </Panel>
                <div className="flex min-w-0 flex-col gap-4">
                    <Panel title="Cronómetro">
                        <Row>
                            <TimerButton taskKey="T4" running startedAt={runningT4} clockStartAt={NOW_ISO} size="lg" onStart={() => undefined} onStop={() => undefined} />
                            <TimerButton taskKey="T5" running={false} size="lg" onStart={() => undefined} onStop={() => undefined} />
                            <TimerButton taskKey="T7" running={false} size="icon" onStart={() => undefined} onStop={() => undefined} />
                        </Row>
                    </Panel>
                    <VaultZone
                        title="Bóveda · zona protegida"
                        description="Siempre oscura, en claro y en oscuro, con la franja de seguridad arriba. Se cierra sola a los 10 minutos sin uso."
                        actions={<CountdownRing seconds={24} total={30} label="El valor se oculta en" />}
                    >
                        <ul className="flex flex-col rounded-[12px] border border-line">
                            {VAULT_ITEMS.slice(0, 2).map((item, index) => (
                                <li key={item.id} className={`flex items-center justify-between gap-3 p-3 text-[13px] ${index > 0 ? 'border-t border-line' : ''}`}>
                                    <span className="flex min-w-0 flex-col">
                                        <b className="text-sm">{item.name}</b>
                                        <span className="text-fg-muted">
                                            {item.product} · {item.environment === 'production' ? 'producción' : 'pruebas'}
                                        </span>
                                    </span>
                                    <span className={item.expires_at ? 'font-semibold text-status-maintenance' : 'text-fg-muted'}>
                                        {item.expires_at ? 'Rotar en 12 días' : 'Vigente'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <button
                            type="button"
                            className="h-11 rounded-control border border-status-maintenance text-sm font-bold text-status-maintenance hover:bg-status-maintenance-bg"
                        >
                            Confirmar identidad con Microsoft
                        </button>
                    </VaultZone>
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Horas ahorradas al mes" value="210 h" delta={{ text: '+24 h frente a agosto', direction: 'up', good: true }} />
                <StatTile label="Satisfacción promedio" value="4,2 de 5" note="45 calificaciones de 4 apps" />
                <StatTile label="De solicitud a producción" value="34 días" delta={{ text: '6 días menos que el semestre anterior', direction: 'down', good: true }} />
                <StatTile label="Costo mensual de las apps" value="$ 800.000" note="Hosting, bases de datos y dominios de 5 productos" restricted />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel title="Horas ahorradas por mes" description="Suma de todas las apps en producción">
                    <LineChart
                        title="Horas ahorradas por mes, abril a septiembre de 2026"
                        summary="Subieron de 96 horas en abril a 210 en septiembre."
                        series={[
                            {
                                key: 'hours',
                                label: 'Horas ahorradas',
                                area: true,
                                points: VALUE_METRICS.hours_series.map((point, index) => ({ x: index, y: point.value })),
                            },
                        ]}
                        xTicks={VALUE_METRICS.hours_series.map((point, index) => ({ x: index, label: MONTHS[Number(point.month.slice(5)) - 1] ?? '' }))}
                        formatX={(x) => {
                            const month = VALUE_METRICS.hours_series[x]?.month ?? '';
                            const name = MONTH_NAMES[Number(month.slice(5)) - 1] ?? '';
                            return `${name.charAt(0).toUpperCase()}${name.slice(1)} ${month.slice(0, 4)}`;
                        }}
                        formatY={hoursText}
                    />
                </Panel>
                <Panel title="Horas acumuladas y proyección" description="P-07 · plan aprobado, real y proyección al ritmo actual">
                    <LineChart
                        title="Horas acumuladas de P-07"
                        summary="Plan aprobado hasta 120 horas el 31 de octubre; real 92 horas hoy; proyección 134 horas el 14 de noviembre."
                        series={[
                            { key: 'actual', label: 'Real', color: 1, points: HOURS_CURVE_P07.actual.map((p) => ({ x: dayIndex(p.date), y: p.value })) },
                            { key: 'plan', label: 'Plan aprobado', color: 'muted', points: HOURS_CURVE_P07.plan.map((p) => ({ x: dayIndex(p.date), y: p.value })) },
                            {
                                key: 'projection',
                                label: 'Proyección al ritmo actual',
                                color: 2,
                                dashed: true,
                                points: HOURS_CURVE_P07.projection.map((p) => ({ x: dayIndex(p.date), y: p.value })),
                            },
                        ]}
                        xTicks={['2026-09-30', '2026-10-15', '2026-10-31', '2026-11-14'].map((date) => ({ x: dayIndex(date), label: dayLabel(dayIndex(date)) }))}
                        formatX={dayLabel}
                        formatY={hoursText}
                    />
                </Panel>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel title="Horas ahorradas por área" description="Septiembre, de mayor a menor">
                    <BarList
                        label="Horas ahorradas por área en septiembre"
                        items={VALUE_METRICS.hours_by_area.map((row) => ({ key: row.area, label: row.area, value: row.hours }))}
                        formatValue={hoursText}
                        labelWidth={120}
                    />
                </Panel>
                <Panel title="Solicitudes por estado" description="Los colores de estado solo se usan aquí porque cada barra es un estado.">
                    <BarList
                        label="Solicitudes por estado"
                        items={VALUE_METRICS.requests_by_status.map((row) => ({ key: row.status, status: row.status, value: row.count }))}
                        formatValue={(value) => String(value)}
                        labelWidth={210}
                    />
                </Panel>
            </div>

            <DiffView label="Versión 6 comparada con la actual (7)" lines={DIFF_MANUAL_6_7} />

            <MarkdownEditor label="Contenido del manual de usuario" value={markdown} onChange={setMarkdown} minHeight={360} />

            <Dialog
                open={dialog}
                onClose={() => setDialog(false)}
                title="Rechazar GIA-097"
                description="Quien la pidió recibe el motivo por correo y Telegram."
                actions={
                    <>
                        <Button variant="secondary" onClick={() => setDialog(false)}>
                            Cancelar
                        </Button>
                        <Button variant="danger" onClick={() => setDialog(false)}>
                            Rechazar
                        </Button>
                    </>
                }
            >
                <TextField label="Motivo" defaultValue={REJECTION_REASON} />
            </Dialog>
            <Drawer
                open={drawer}
                onClose={() => setDrawer(false)}
                eyebrow="T4 · P-07 Visualizador de cartera · GIA-139"
                title="Consulta de cartera por asesor desde SAP B1"
                footer={
                    <Button variant="secondary" onClick={() => setDrawer(false)}>
                        Cerrar
                    </Button>
                }
            >
                <MarkdownPreview source="Traer de SAP B1 la cartera de cada asesor con su zona y los días vencidos, para que el filtro del visualizador responda **sin exportar Excel**." />
            </Drawer>
        </Section>
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
