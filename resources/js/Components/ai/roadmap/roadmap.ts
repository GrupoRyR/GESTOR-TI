/** Roadmap card copy: the second line and the +1 button's accessible name. */
import { formatShortDate } from '../../../lib/format';

export interface RoadmapLineInput {
    area: { id: number; name: string };
    /** Server-written next step: "Espera un dato de Mercadeo". */
    note: string | null;
    planned_start: string | null;
}

/** "Cartera · Entrega estimada 14 nov", "Contabilidad · Empieza el 3 nov" or just the area. */
export function roadmapLine(item: RoadmapLineInput): string {
    const second = item.note ?? (item.planned_start ? `Empieza el ${formatShortDate(item.planned_start)}` : null);
    return second ? `${item.area.name} · ${second}` : item.area.name;
}

export function voteLabel(votes: number, voted: boolean): string {
    const count = `${votes} ${votes === 1 ? 'voto' : 'votos'}`;
    if (voted) return `${count}, incluido el tuyo. Quitar mi voto`;
    return votes === 0 ? 'Sumar mi voto' : `${count}, sumar el mío`;
}
