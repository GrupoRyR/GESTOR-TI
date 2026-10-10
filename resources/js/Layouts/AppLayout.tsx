import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Avatar } from '@/Components/ui/Avatar';
import { brandScopeStyle } from '@/Components/ui/brandScope';
import { Button } from '@/Components/ui/Button';
import { CompanyLogo } from '@/Components/ui/CompanyLogo';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { FlashMessages } from '@/Components/ui/FlashMessages';
import { Icon } from '@/Components/ui/Icon';
import { IconButton } from '@/Components/ui/IconButton';
import { SegmentedControl } from '@/Components/ui/SegmentedControl';
import { cx } from '@/Components/ui/utils';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import type { Company, ModuleItem, SharedProps } from '@/types/shared';

export interface AppLayoutProps {
    shared: SharedProps;
    /** Document title (`<Head>`). */
    title: string;
    /** Key of the current module, highlighted in the navigation. */
    active?: string;
    /** Numeric badges per module key, e.g. `{ ai: 5 }`. */
    badges?: Record<string, number>;
    /** Extra controls in the top bar, before "Preguntar". */
    headerActions?: ReactNode;
    /** Full-width bar under the top bar (e.g. AdminLayout tabs). */
    subnav?: ReactNode;
    children: ReactNode;
}

const ALL = 'all';
const PHONE_SLOTS = 3;

/** Company whose brand dresses the shell: the selected one, or the user's own in "Todas". */
export function brandCompany(shared: SharedProps): Company | undefined {
    const { companies, current_company_id, auth } = shared;
    return (
        companies.find((company) => company.id === current_company_id) ??
        companies.find((company) => company.id === auth.user.company_id) ??
        companies[0]
    );
}

function switchCompany(value: string) {
    router.post(route('company.switch'), { company_id: value === ALL ? null : Number(value) }, { preserveScroll: true });
}

function Badge({ count, className }: { count: number | undefined; className?: string }) {
    if (!count) return null;
    return (
        <span
            className={cx(
                'tabular flex items-center justify-center rounded-full bg-fg px-1.5 text-xs leading-none font-bold text-on-fg',
                className,
            )}
        >
            {count > 99 ? '99+' : count}
            <span className="sr-only"> pendientes</span>
        </span>
    );
}

// ---- navigation -----------------------------------------------------------

interface NavProps {
    modules: ModuleItem[];
    active?: string;
    badges?: Record<string, number>;
}

function SideNav({ modules, active, badges, company }: NavProps & { company: Company | undefined }) {
    return (
        <nav
            aria-label="Módulos"
            className="sticky top-0 hidden h-dvh flex-col gap-1 overflow-y-auto border-r border-line bg-surface px-3.5 py-5 lg:flex"
        >
            <div className="flex flex-col items-start gap-2 px-2 pt-1.5 pb-5">
                {company ? <CompanyLogo company={company} height={20} /> : null}
                <span className="text-[13px] font-bold text-fg-soft">Gestor TI</span>
            </div>
            <ul className="flex flex-col gap-1">
                {modules.map((module) => {
                    const current = module.key === active;
                    return (
                        <li key={module.key}>
                            <Link
                                href={route(module.route)}
                                aria-current={current ? 'page' : undefined}
                                className={cx(
                                    'flex h-11 items-center gap-3 rounded-control px-3 text-sm transition-colors duration-150',
                                    current ? 'bg-brand-tint font-bold text-brand-ink' : 'font-semibold text-fg hover:bg-subtle',
                                )}
                            >
                                <Icon name={module.icon} size={18} />
                                <span className="min-w-0 flex-1 truncate">{module.name}</span>
                                <Badge count={badges?.[module.key]} className="h-[22px] min-w-[22px]" />
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

function Rail({ modules, active, badges, company }: NavProps & { company: Company | undefined }) {
    return (
        <nav
            aria-label="Módulos"
            className="sticky top-0 hidden h-dvh flex-col items-center gap-1.5 overflow-y-auto border-r border-line bg-surface px-2 py-4 md:flex lg:hidden"
        >
            {company ? <CompanyMark company={company} size={40} variant="solid" className="mb-3" /> : null}
            {modules.map((module) => {
                const current = module.key === active;
                const count = badges?.[module.key];
                return (
                    <Link
                        key={module.key}
                        href={route(module.route)}
                        aria-current={current ? 'page' : undefined}
                        className={cx(
                            'relative flex min-h-[60px] w-[68px] flex-col items-center justify-center gap-1 rounded-control px-1 py-2 text-center text-[11px] leading-[1.15] transition-colors duration-150',
                            current ? 'bg-brand-tint font-bold text-brand-ink' : 'font-semibold text-fg hover:bg-subtle',
                        )}
                    >
                        <Icon name={module.icon} size={20} />
                        <span className="line-clamp-2 max-w-full [overflow-wrap:anywhere] hyphens-auto">{module.name}</span>
                        <Badge count={count} className="absolute top-1.5 right-3 h-[18px] min-w-[18px] text-[11px]" />
                    </Link>
                );
            })}
        </nav>
    );
}

interface BottomBarProps extends NavProps {
    shared: SharedProps;
}

function BottomBar({ modules, active, badges, shared }: BottomBarProps) {
    const dialogRef = useRef<HTMLDialogElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const [open, setOpen] = useState(false);
    const primary = modules.slice(0, PHONE_SLOTS);
    const rest = modules.slice(PHONE_SLOTS);
    const moreActive = rest.some((module) => module.key === active);
    const user = shared.auth.user;

    const openSheet = () => {
        dialogRef.current?.showModal();
        setOpen(true);
    };
    const closeSheet = () => dialogRef.current?.close();

    const itemClasses = (current: boolean) =>
        cx(
            'relative flex min-h-[65px] flex-col items-center justify-center gap-[3px] text-[11px] focus-visible:-outline-offset-2',
            current ? 'font-bold text-brand-ink' : 'font-semibold text-fg-soft',
        );

    return (
        <>
            <nav
                aria-label="Módulos"
                className="fixed inset-x-0 bottom-0 z-30 grid border-t border-line bg-surface pb-[max(6px,env(safe-area-inset-bottom))] md:hidden"
                style={{ gridTemplateColumns: `repeat(${primary.length + 1}, minmax(0, 1fr))` }}
            >
                {primary.map((module) => {
                    const current = module.key === active;
                    return (
                        <Link
                            key={module.key}
                            href={route(module.route)}
                            aria-current={current ? 'page' : undefined}
                            className={itemClasses(current)}
                        >
                            <Icon name={module.icon} size={22} />
                            <span className="max-w-full truncate px-1">{module.name}</span>
                            <Badge
                                count={badges?.[module.key]}
                                className="absolute top-2 left-[calc(50%+4px)] h-4 min-w-4 text-[10px]"
                            />
                        </Link>
                    );
                })}
                <button
                    ref={triggerRef}
                    type="button"
                    aria-haspopup="dialog"
                    aria-expanded={open}
                    onClick={openSheet}
                    className={itemClasses(moreActive || open)}
                >
                    <Icon name="more" size={22} />
                    Más
                </button>
            </nav>

            <dialog
                ref={dialogRef}
                aria-labelledby="mas-titulo"
                onClose={() => {
                    setOpen(false);
                    triggerRef.current?.focus();
                }}
                onClick={(event) => {
                    // Click on the backdrop (the dialog box itself, outside the sheet).
                    if (event.target === event.currentTarget) closeSheet();
                }}
                className="m-0 mt-auto max-h-[88dvh] w-full max-w-none overflow-y-auto rounded-t-card bg-ground p-0 text-fg backdrop:bg-black/45 md:hidden"
            >
                <div className="flex flex-col gap-5 px-4 pt-4 pb-[max(20px,env(safe-area-inset-bottom))]">
                    <div className="flex items-center gap-3">
                        <Avatar name={user.name} src={user.avatar_url} size="lg" tone="strong" />
                        <span className="flex min-w-0 flex-1 flex-col">
                            <h2 id="mas-titulo" className="truncate font-sans text-base font-bold tracking-normal">
                                {user.name}
                            </h2>
                            {user.roles.length > 0 ? (
                                <span className="truncate text-[13px] text-fg-muted">{user.roles.join(' · ')}</span>
                            ) : null}
                        </span>
                        <IconButton icon="x" label="Cerrar menú" onClick={closeSheet} />
                    </div>

                    <ul className="overflow-hidden rounded-card border border-line bg-surface">
                        {rest.map((module, index) => {
                            const current = module.key === active;
                            return (
                                <li key={module.key} className={cx(index > 0 && 'border-t border-line')}>
                                    <Link
                                        href={route(module.route)}
                                        aria-current={current ? 'page' : undefined}
                                        onClick={closeSheet}
                                        className={cx(
                                            'flex h-14 items-center gap-3.5 px-4 text-[15px] font-semibold focus-visible:-outline-offset-2',
                                            current && 'bg-brand-tint text-brand-ink',
                                        )}
                                    >
                                        <Icon name={module.icon} size={20} />
                                        <span className="min-w-0 flex-1 truncate">{module.name}</span>
                                        <Badge count={badges?.[module.key]} className="h-[22px] min-w-[22px]" />
                                    </Link>
                                </li>
                            );
                        })}
                        <li className={cx(rest.length > 0 && 'border-t border-line')}>
                            <Link
                                href={route('profile.edit')}
                                onClick={closeSheet}
                                className="flex h-14 items-center gap-3.5 px-4 text-[15px] font-semibold focus-visible:-outline-offset-2"
                            >
                                <Icon name="user" size={20} />
                                Perfil y notificaciones
                            </Link>
                        </li>
                    </ul>

                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="flex h-12 w-full items-center justify-center gap-2 rounded-control border border-line-strong bg-surface text-[15px] font-bold text-status-failure"
                    >
                        <Icon name="logout" size={18} />
                        Cerrar sesión
                    </Link>
                </div>
            </dialog>
        </>
    );
}

// ---- top bar --------------------------------------------------------------

function SearchField() {
    const [query, setQuery] = useState('');
    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(route('search'), query.trim() ? { q: query.trim() } : {});
    };

    return (
        <form role="search" action={route('search')} method="get" onSubmit={onSubmit} className="hidden max-w-[480px] min-w-0 flex-1 lg:flex">
            <label className="flex h-11 w-full items-center gap-2.5 rounded-control border border-line-strong bg-surface px-3.5 text-fg-muted has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg">
                <Icon name="search" size={16} />
                <span className="sr-only">Buscar</span>
                <input
                    type="search"
                    name="q"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Buscar solicitudes, personas, fichas…"
                    className="min-w-0 flex-1 bg-transparent text-sm text-fg outline-none placeholder:text-fg-muted"
                />
            </label>
        </form>
    );
}

function CompanyPhoneSelect({ companies, value }: { companies: Company[]; value: string }) {
    return (
        <label className="relative inline-flex">
            <span className="sr-only">Empresa</span>
            <select
                value={value}
                onChange={(event) => switchCompany(event.target.value)}
                className="h-10 max-w-[150px] appearance-none truncate rounded-chip border border-line-strong bg-surface pr-8 pl-2.5 text-sm font-bold text-fg"
            >
                {companies.map((company) => (
                    <option key={company.id} value={String(company.id)}>
                        {company.short_name}
                    </option>
                ))}
                <option value={ALL}>Todas</option>
            </select>
            <span className="pointer-events-none absolute inset-y-0 right-2.5 flex items-center">
                <Icon name="chevron-down" size={14} strokeWidth={2.2} />
            </span>
        </label>
    );
}

function TopBar({
    shared,
    company,
    headerActions,
}: {
    shared: SharedProps;
    company: Company | undefined;
    headerActions?: ReactNode;
}) {
    const { auth, companies, current_company_id, notifications_unread, modules } = shared;
    const user = auth.user;
    const canViewAll = can(user, 'companies.view_all');
    const selected = current_company_id === null ? ALL : String(current_company_id);
    const assistant = modules.find((module) => module.key === 'assistant' && module.active);
    const bellLabel = notifications_unread > 0 ? `Notificaciones, ${notifications_unread} sin leer` : 'Notificaciones';

    return (
        <header className="sticky top-0 z-20 flex h-[60px] items-center gap-2 border-b border-line bg-surface pr-2 pl-4 md:h-[68px] md:gap-2.5 md:px-5 lg:gap-4 lg:px-8">
            {/* Phone: brand mark + compact company picker. */}
            <div className="flex min-w-0 items-center gap-2 md:hidden">
                {company ? <CompanyMark company={company} size={32} variant="solid" decorative /> : null}
                {canViewAll ? (
                    <CompanyPhoneSelect companies={companies} value={selected} />
                ) : company ? (
                    <span className="truncate text-sm font-bold">{company.short_name}</span>
                ) : null}
            </div>

            {/* Tablet and desktop: segmented company picker. */}
            {canViewAll ? (
                <div className="hidden md:flex">
                    <SegmentedControl
                        label="Empresa"
                        value={selected}
                        onChange={switchCompany}
                        options={[
                            ...companies.map((option) => ({ value: String(option.id), label: option.short_name })),
                            { value: ALL, label: 'Todas' },
                        ]}
                    />
                </div>
            ) : null}

            <SearchField />

            <div className="ml-auto flex items-center gap-1 lg:gap-2">
                {headerActions}
                <span className="inline-flex lg:hidden">
                    <IconButton icon="search" label="Buscar" href={route('search')} />
                </span>
                {assistant ? (
                    <>
                        <span className="hidden md:inline-flex lg:hidden">
                            <IconButton icon="chat" label="Preguntar al Asistente" href={route(assistant.route)} />
                        </span>
                        <span className="hidden lg:inline-flex">
                            <Button variant="secondary" icon="chat" href={route(assistant.route)} className="px-3.5">
                                Preguntar
                            </Button>
                        </span>
                    </>
                ) : null}
                <IconButton icon="bell" label={bellLabel} badge={notifications_unread} href={route('notifications.index')} />
                <Link
                    href={route('profile.edit')}
                    aria-label={`Perfil de ${user.name}`}
                    title={`Perfil de ${user.name}`}
                    className="hidden rounded-full md:inline-flex"
                >
                    <Avatar name={user.name} src={user.avatar_url} size="md" tone="strong" />
                </Link>
            </div>
        </header>
    );
}

// ---- layout ---------------------------------------------------------------

/**
 * Authenticated shell. Navigation comes only from `shared.modules` (inactive
 * modules never render): side nav ≥ 1024 px, icon rail 768–1023 px, bottom
 * bar with "Más" below 768 px. The brand of the selected company (or the
 * user's own in "Todas") is applied for light and dark.
 */
export function AppLayout({ shared, title, active, badges, headerActions, subnav, children }: AppLayoutProps) {
    const company = brandCompany(shared);
    const modules = shared.modules.filter((module) => module.active);

    return (
        <div
            className="brand-scope min-h-dvh bg-ground text-fg md:grid md:grid-cols-[84px_minmax(0,1fr)] lg:grid-cols-[248px_minmax(0,1fr)]"
            style={company ? brandScopeStyle(company.colors) : undefined}
        >
            <Head title={title} />
            <a
                href="#contenido"
                className="sr-only z-50 rounded-control bg-fg px-4 py-3 text-sm font-bold text-on-fg focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >
                Saltar al contenido
            </a>

            <SideNav modules={modules} active={active} badges={badges} company={company} />
            <Rail modules={modules} active={active} badges={badges} company={company} />

            <div className="flex min-w-0 flex-col">
                <TopBar shared={shared} company={company} headerActions={headerActions} />
                {subnav}
                <main id="contenido" tabIndex={-1} className="flex-1 px-4 pt-5 pb-28 outline-none md:px-5 md:py-6 lg:px-8">
                    <FlashMessages flash={shared.flash} className="mb-4" />
                    {children}
                </main>
            </div>

            <BottomBar modules={modules} active={active} badges={badges} shared={shared} />
        </div>
    );
}

export default AppLayout;
