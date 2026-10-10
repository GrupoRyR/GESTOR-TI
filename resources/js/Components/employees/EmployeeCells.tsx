import { Chip } from '@/Components/ui/Chip';
import type { EmployeeRow } from '@/types/pages/employees';

/** Area name, or the amber "Sin área · asignar" chip. */
export function AreaCell({ area }: { area: EmployeeRow['area'] }) {
    if (area) return <span className="truncate">{area.name}</span>;
    return (
        <Chip tone="amber" size="sm">
            Sin área · asignar
        </Chip>
    );
}

/** Gestor TI access: first role as a chip, or "No ha entrado". */
export function AccountCell({ user }: { user: EmployeeRow['user'] }) {
    if (!user) return <span className="text-[13px] text-fg-muted">No ha entrado</span>;
    const [first, ...rest] = user.roles;
    if (!first) return <span className="text-[13px] text-fg-muted">Sin rol</span>;
    return (
        <span className="flex min-w-0 items-center gap-1.5">
            <Chip size="sm" title={user.roles.join(', ')}>
                {first}
            </Chip>
            {rest.length > 0 ? <span className="text-xs text-fg-muted">+{rest.length}</span> : null}
        </span>
    );
}

/** "Coordinadora de ventas · Ventas" for the phone card. */
export function employeeLine(employee: EmployeeRow): string {
    const area = employee.area ? employee.area.name : 'sin área';
    return employee.job_title ? `${employee.job_title} · ${area}` : area;
}
