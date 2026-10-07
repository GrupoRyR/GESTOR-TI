import { useEffect, useState, type FormEvent } from 'react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import type { EmployeeArea, EmployeeFilters, EmployeeStatusFilter } from '@/types/pages/employees';
import type { Company } from '@/types/shared';

interface FilterSelectProps {
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}

/** Native select dressed as a filter chip; option labels carry the prefix ("Área: Ventas"). */
function FilterSelect({ label, value, options, onChange }: FilterSelectProps) {
    return (
        <label className="relative inline-flex shrink-0">
            <span className="sr-only">{label}</span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="h-11 max-w-[240px] appearance-none truncate rounded-control border border-line-strong bg-surface pr-8 pl-3 text-[13px] font-semibold text-fg hover:border-fg-muted md:text-sm"
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <span className="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-fg-muted">
                <Icon name="chevron-down" size={14} strokeWidth={2.2} />
            </span>
        </label>
    );
}

const STATUS_OPTIONS: { value: EmployeeStatusFilter; label: string }[] = [
    { value: 'active', label: 'Estado: activos' },
    { value: 'inactive', label: 'Estado: inactivos' },
    { value: 'all', label: 'Estado: todos' },
];

export interface EmployeeFiltersBarProps {
    filters: EmployeeFilters;
    areas: EmployeeArea[];
    withoutAreaCount: number;
    /** Companies to choose from; only passed in "Todas". */
    companies: Company[] | null;
    onChange: (filters: EmployeeFilters) => void;
}

/**
 * Search (submits on Enter or after a pause), area, status and the
 * "Sin área · N" toggle. On phone the selects scroll sideways in one row.
 */
export function EmployeeFiltersBar({ filters, areas, withoutAreaCount, companies, onChange }: EmployeeFiltersBarProps) {
    const [query, setQuery] = useState(filters.q ?? '');

    // Follow the server value after a visit (e.g. "Quitar filtros").
    useEffect(() => setQuery(filters.q ?? ''), [filters.q]);

    // Search as you type, after a short pause.
    useEffect(() => {
        const next = query.trim() || null;
        if (next === filters.q) return;
        const timer = setTimeout(() => onChange({ ...filters, q: next }), 400);
        return () => clearTimeout(timer);
    }, [query, filters, onChange]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        onChange({ ...filters, q: query.trim() || null });
    };

    const visibleAreas = filters.company_id === null ? areas : areas.filter((area) => area.company_id === filters.company_id);
    const areaLabel = (area: EmployeeArea) => {
        if (!companies) return area.name;
        const company = companies.find((item) => item.id === area.company_id);
        return company && filters.company_id === null ? `${area.name} (${company.short_name})` : area.name;
    };

    return (
        <div className="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center md:gap-2">
            <form role="search" onSubmit={submit} className="md:w-[280px]">
                <label className="flex h-11 items-center gap-2 rounded-control border border-line-strong bg-surface px-3 text-fg-muted has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg">
                    <Icon name="search" size={16} />
                    <span className="sr-only">Buscar colaboradores</span>
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Nombre, correo o cargo"
                        className="min-w-0 flex-1 bg-transparent text-base text-fg outline-none placeholder:text-fg-muted md:text-sm"
                    />
                </label>
            </form>

            <div className="-mx-4 flex gap-2 overflow-x-auto px-4 md:mx-0 md:overflow-visible md:px-0">
                {companies ? (
                    <FilterSelect
                        label="Empresa"
                        value={filters.company_id === null ? '' : String(filters.company_id)}
                        onChange={(value) => onChange({ ...filters, company_id: value ? Number(value) : null, area_id: null })}
                        options={[
                            { value: '', label: 'Todas las empresas' },
                            ...companies.map((company) => ({ value: String(company.id), label: company.short_name })),
                        ]}
                    />
                ) : null}
                <FilterSelect
                    label="Área"
                    value={filters.area_id === null ? '' : String(filters.area_id)}
                    onChange={(value) => onChange({ ...filters, area_id: value ? Number(value) : null, without_area: false })}
                    options={[
                        { value: '', label: 'Área: todas' },
                        ...visibleAreas.map((area) => ({ value: String(area.id), label: `Área: ${areaLabel(area)}` })),
                    ]}
                />
                <FilterSelect
                    label="Estado"
                    value={filters.status}
                    onChange={(value) => onChange({ ...filters, status: value as EmployeeStatusFilter })}
                    options={STATUS_OPTIONS}
                />
                <button
                    type="button"
                    aria-pressed={filters.without_area}
                    onClick={() => onChange({ ...filters, without_area: !filters.without_area, area_id: null })}
                    className={cx(
                        'inline-flex h-11 shrink-0 items-center gap-1.5 rounded-control border px-3 text-[13px] font-semibold transition-colors duration-150 md:text-sm',
                        filters.without_area
                            ? 'border-fg bg-fg text-on-fg'
                            : 'border-line-strong bg-surface text-fg hover:border-fg-muted',
                    )}
                >
                    <span className="tabular">Sin área · {withoutAreaCount}</span>
                    {filters.without_area ? <Icon name="x" size={14} strokeWidth={2.4} /> : null}
                </button>
            </div>
        </div>
    );
}
