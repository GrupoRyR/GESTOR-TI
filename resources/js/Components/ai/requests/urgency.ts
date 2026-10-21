import type { ChoiceOption } from '@/Components/ui/ChoiceCard';
import { formatShortDate } from '@/lib/format';
import type { Urgency } from '@/lib/priority';

/**
 * Urgency choices of the request wizard ("Pedir un desarrollo") and of
 * "Proponer proyecto". "Este mes · Antes del 31 oct" is computed from `today`.
 */
export function urgencyOptions(today: string): ChoiceOption<Urgency>[] {
    const [year, month] = today.split('-').map(Number);
    const monthEnd = new Date(Date.UTC(year ?? 2026, month ?? 1, 0)).toISOString().slice(0, 10);
    return [
        { value: 'low', title: 'Puede esperar', description: 'No frena el trabajo' },
        { value: 'medium', title: 'Este trimestre', description: 'En los próximos tres meses' },
        { value: 'high', title: 'Este mes', description: `Antes del ${formatShortDate(monthEnd)}` },
        { value: 'urgent', title: 'Urgente', description: 'Se pierde plata o clientes hoy' },
    ];
}
