/**
 * Type-specific fields of a knowledge card (boards C-KnowledgeCard,
 * C-KnowledgeForm, C-KnowledgeTypes): labels, which are required, the flat
 * form values the shared form edits, and the default review date.
 */
import type { KnowledgeCardBody, KnowledgeType } from '../types/assistant';

export interface KnowledgeFieldDef {
    key: string;
    label: string;
    /** Required by the server (non-nullable in the type). */
    required: boolean;
    /** Long text: a textarea in the form. */
    multiline?: boolean;
    /** Ordered list of steps (procedure). */
    steps?: boolean;
    /** Input placeholder ("Ej.: …"). */
    placeholder: string;
    /** Help under the field. */
    hint?: string;
}

export const KNOWLEDGE_FIELDS: Record<KnowledgeType, KnowledgeFieldDef[]> = {
    system: [
        { key: 'what_is', label: 'Qué es', required: true, multiline: true, placeholder: 'Ej.: ERP de Segurex y Derivados' },
        { key: 'runs_on', label: 'Dónde corre', required: false, placeholder: 'Ej.: servidor local, GoDaddy, la nube de Microsoft' },
        { key: 'access', label: 'Acceso', required: false, placeholder: 'Ej.: por VPN, con usuario de SAP', hint: 'Cómo se entra, nunca la contraseña.' },
        { key: 'version', label: 'Versión', required: false, placeholder: 'Ej.: v2.4.0' },
    ],
    report: [
        { key: 'shows', label: 'Qué muestra', required: true, multiline: true, placeholder: 'Ej.: los pedidos del día anterior, tomados de SAP B1' },
        { key: 'source_system', label: 'Quién lo envía · sistema origen', required: false, placeholder: 'Ej.: Toma Pedidos SAP, con datos de SAP B1' },
        {
            key: 'recipients',
            label: 'Destinatarios',
            required: false,
            placeholder: 'Ej.: gerencia y coordinadores de ventas',
            hint: 'El asistente lo usa para responder “¿a quién le llega?”.',
        },
        {
            key: 'schedule',
            label: 'Horario',
            required: false,
            placeholder: 'Ej.: lunes a las 7:00 a. m.',
            hint: 'Si no lo sabes, déjalo vacío. El asistente dirá que no lo tiene documentado.',
        },
        { key: 'format', label: 'Formato', required: false, placeholder: 'Ej.: Excel por correo' },
    ],
    data_flow: [
        { key: 'origin_destination', label: 'Origen y destino', required: true, placeholder: 'Ej.: Toma Pedidos a SAP B1' },
        { key: 'transport', label: 'Cómo viaja', required: false, placeholder: 'Ej.: Service Layer de SAP B1' },
        { key: 'frequency', label: 'Frecuencia', required: false, placeholder: 'Ej.: cada 5 minutos' },
        { key: 'on_failure', label: 'Qué pasa si falla', required: false, multiline: true, placeholder: 'Ej.: el pedido queda “pendiente de sincronizar”' },
    ],
    procedure: [
        {
            key: 'steps',
            label: 'Pasos numerados',
            required: true,
            steps: true,
            placeholder: 'Ej.: Abrir SAP Business One',
            hint: 'Un paso por línea. Si no conoces la ruta de un menú, escribe [RUTA EN SAP]: el asistente no la inventa.',
        },
        { key: 'who_can', label: 'Quién lo puede hacer', required: false, placeholder: 'Ej.: gerencia de cartera y asesores' },
    ],
    glossary: [
        { key: 'term', label: 'Término', required: true, placeholder: 'Ej.: pedido bloqueado por cartera' },
        { key: 'definition', label: 'Definición', required: true, multiline: true, placeholder: 'Ej.: pedido que SAP retiene porque el cliente superó su cupo' },
        { key: 'also_called', label: 'También se dice', required: false, placeholder: 'Ej.: pedido retenido' },
    ],
};

export interface KnowledgeFieldRow {
    key: string;
    label: string;
    /** A list for procedure steps; null when empty. */
    value: string | string[] | null;
}

/** Labelled rows of a card's type fields, in order (card detail). */
export function cardFieldRows(body: KnowledgeCardBody): KnowledgeFieldRow[] {
    const fields = body.fields as unknown as Record<string, string | string[] | null>;
    return KNOWLEDGE_FIELDS[body.type].map((field) => ({ key: field.key, label: field.label, value: fields[field.key] ?? null }));
}

/** Flat text values of every type (keys never overlap) plus the procedure steps. */
export interface KnowledgeFormValues {
    values: Record<string, string>;
    steps: string[];
}

/** Form start values from a card body (Edit) or blank (Create). Always at least one step row. */
export function formValuesFromBody(body: KnowledgeCardBody | null): KnowledgeFormValues {
    const values: Record<string, string> = {};
    for (const fields of Object.values(KNOWLEDGE_FIELDS)) {
        for (const field of fields) if (!field.steps) values[field.key] = '';
    }
    let steps: string[] = [''];
    if (body) {
        const source = body.fields as unknown as Record<string, string | string[] | null>;
        for (const field of KNOWLEDGE_FIELDS[body.type]) {
            const value = source[field.key];
            if (field.steps) steps = Array.isArray(value) && value.length > 0 ? [...value] : [''];
            else values[field.key] = typeof value === 'string' ? value : '';
        }
    }
    return { values, steps };
}

/** The `fields` of the payload: only `type`'s keys, trimmed, empty optional text as null, empty steps dropped. */
export function bodyFromForm(type: KnowledgeType, values: Record<string, string>, steps: string[]): KnowledgeCardBody {
    const fields: Record<string, string | string[] | null> = {};
    for (const field of KNOWLEDGE_FIELDS[type]) {
        if (field.steps) {
            fields[field.key] = steps.map((step) => step.trim()).filter((step) => step !== '');
            continue;
        }
        const text = (values[field.key] ?? '').trim();
        fields[field.key] = text === '' && !field.required ? null : text;
    }
    return { type, fields } as unknown as KnowledgeCardBody;
}

const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/** `today` plus `months` (6 by default), clamped to the end of a shorter month. `YYYY-MM-DD`. */
export function defaultReviewDate(today: string, months = 6): string {
    const match = DATE.exec(today);
    if (!match) throw new RangeError(`Expected YYYY-MM-DD: ${today}`);
    const total = Number(match[1]) * 12 + (Number(match[2]) - 1) + months;
    const year = Math.floor(total / 12);
    const month = (total % 12) + 1;
    const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const day = Math.min(Number(match[3]), lastDay);
    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/** A whole value that is a bracket placeholder for an unknown fact ("[VERSIÓN]"). */
export function isPlaceholderText(value: string | null | undefined): boolean {
    if (!value) return false;
    return /^\[[^\]]+\]$/.test(value.trim());
}
