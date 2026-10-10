/**
 * Knowledge base helpers (boards C-Knowledge, C-KnowledgeTypes,
 * C-KnowledgeCard): type catalog, visibility labels and the review date
 * status. Review dates are calendar days (`YYYY-MM-DD`) compared with
 * today's Bogotá date, both sent by the server.
 */
import type { IconName } from '../Components/ui/Icon';
import type { KnowledgeType, KnowledgeVisibility, ReviewStatus } from '../types/assistant';
import { formatShortDate } from './format';

export interface KnowledgeTypeInfo {
    label: string;
    /** Neutral icon (never a status color). */
    icon: IconName;
    /** One line for the type picker: "Qué muestra, quién lo recibe y a qué hora". */
    hint: string;
}

export const KNOWLEDGE_TYPES: Record<KnowledgeType, KnowledgeTypeInfo> = {
    system: { label: 'Sistema', icon: 'kb-system', hint: 'Un programa o servidor: qué es y dónde corre' },
    report: { label: 'Informe', icon: 'kb-report', hint: 'Qué muestra, quién lo recibe y a qué hora' },
    data_flow: { label: 'Flujo de datos', icon: 'kb-flow', hint: 'Cómo viaja un dato de un sistema a otro' },
    procedure: { label: 'Procedimiento', icon: 'kb-procedure', hint: 'Pasos para hacer algo, en orden' },
    glossary: { label: 'Glosario', icon: 'kb-glossary', hint: 'Qué significa una palabra de la empresa' },
};

export const KNOWLEDGE_TYPE_ORDER: KnowledgeType[] = ['system', 'report', 'data_flow', 'procedure', 'glossary'];

export function knowledgeTypeLabel(type: KnowledgeType): string {
    return KNOWLEDGE_TYPES[type].label;
}

const VISIBILITY_LABELS: Record<KnowledgeVisibility, string> = {
    it: 'Solo TI',
    company: 'Toda la empresa',
};

export function visibilityLabel(visibility: KnowledgeVisibility): string {
    return VISIBILITY_LABELS[visibility];
}

/** Reviews due within this many days show as "due soon". */
export const REVIEW_SOON_DAYS = 30;

const DAY = 86_400_000;

function calendarDay(date: string): number {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
    if (!match) throw new RangeError(`Expected YYYY-MM-DD: ${date}`);
    return Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])) / DAY;
}

/** Calendar days from `today` to `date` (negative when past). */
export function daysUntil(date: string, today: string): number {
    return calendarDay(date) - calendarDay(today);
}

export function reviewStatus(reviewAt: string, today: string): ReviewStatus {
    const days = daysUntil(reviewAt, today);
    if (days < 0) return 'overdue';
    if (days <= REVIEW_SOON_DAYS) return 'due_soon';
    return 'ok';
}

/** Short date, with the year only when it is not the current one: "30 nov", "15 ene 2027". */
function reviewDate(reviewAt: string, today: string): string {
    const short = formatShortDate(reviewAt);
    return reviewAt.slice(0, 4) === today.slice(0, 4) ? short : `${short} ${reviewAt.slice(0, 4)}`;
}

/** List cell: "Vencida 30 sep" or "Revisar 15 ene 2027". */
export function reviewLabel(reviewAt: string, today: string): string {
    const date = reviewDate(reviewAt, today);
    return daysUntil(reviewAt, today) < 0 ? `Vencida ${date}` : `Revisar ${date}`;
}

/** Card detail: "Faltan 100 días", "Vence hoy", "Venció hace 7 días". */
export function reviewSentence(reviewAt: string, today: string): string {
    const days = daysUntil(reviewAt, today);
    if (days === 0) return 'Vence hoy';
    if (days > 0) return days === 1 ? 'Falta 1 día' : `Faltan ${days} días`;
    const late = -days;
    return late === 1 ? 'Venció hace 1 día' : `Venció hace ${late} días`;
}

/** "Segurex", "Segurex y Derivados" (ids in ascending order). */
export function companyScopeLabel(companyIds: number[], names: Record<number, string>): string {
    if (companyIds.length === 0) return 'Sin empresa';
    const labels = [...companyIds].sort((a, b) => a - b).map((id) => names[id] ?? `[EMPRESA ${id}]`);
    if (labels.length === 1) return labels[0] ?? '';
    return `${labels.slice(0, -1).join(', ')} y ${labels[labels.length - 1]}`;
}
