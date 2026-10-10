/**
 * Excel import, step 2 (board C-ImportMapping): match the sheet's headers to
 * card fields. Matching ignores accents, case and punctuation and knows the
 * usual synonyms ("Destinatarios" → Quién lo recibe). The sheet has one card
 * type, so a "Tipo" column is fixed and only checked. The server repeats the
 * match when it builds the preview; this only drives the UI.
 */
import type { ImportColumn, ImportField, ImportMatchKind, KnowledgeType } from '../types/assistant';

interface FieldSpec extends ImportField {
    /** Header spellings that match automatically (normalized on use). */
    synonyms: string[];
}

const COMMON: FieldSpec[] = [
    { key: 'title', label: 'Título', required: true, synonyms: ['titulo', 'nombre', 'nombre de la ficha', 'ficha'] },
    { key: 'type', label: 'Tipo de ficha (fijo)', required: false, synonyms: ['tipo', 'tipo de ficha'] },
    { key: 'company', label: 'Empresa', required: true, synonyms: ['empresa', 'compania', 'empresas'] },
    { key: 'area', label: 'Área', required: false, synonyms: ['area', 'departamento', 'proceso'] },
    { key: 'description', label: 'Descripción', required: false, synonyms: ['descripcion', 'detalle', 'resumen'] },
    { key: 'visibility', label: 'Visibilidad', required: true, synonyms: ['visibilidad', 'quien la ve', 'alcance'] },
    { key: 'owner', label: 'Dueño (por nombre de usuario)', required: false, synonyms: ['dueno', 'responsable', 'propietario'] },
];

const BY_TYPE: Record<KnowledgeType, FieldSpec[]> = {
    system: [
        { key: 'what_is', label: 'Qué es', required: false, synonyms: ['que es', 'para que sirve'] },
        { key: 'runs_on', label: 'Dónde corre', required: false, synonyms: ['donde corre', 'servidor', 'hosting', 'ubicacion'] },
        { key: 'access', label: 'Acceso', required: false, synonyms: ['acceso', 'como entro', 'como se entra'] },
        { key: 'version', label: 'Versión', required: false, synonyms: ['version'] },
    ],
    report: [
        { key: 'shows', label: 'Qué muestra', required: false, synonyms: ['que muestra', 'contenido'] },
        { key: 'source_system', label: 'Sistema de origen', required: false, synonyms: ['sistema de origen', 'sistema origen', 'origen', 'quien lo envia'] },
        { key: 'recipients', label: 'Quién lo recibe', required: false, synonyms: ['quien lo recibe', 'destinatarios', 'destinatario', 'recibe', 'para'] },
        { key: 'schedule', label: 'Horario de envío', required: false, synonyms: ['horario de envio', 'horario', 'hora', 'frecuencia'] },
        { key: 'format', label: 'Formato', required: false, synonyms: ['formato', 'como llega'] },
    ],
    data_flow: [
        { key: 'origin_destination', label: 'Origen y destino', required: false, synonyms: ['origen y destino', 'origen destino'] },
        { key: 'transport', label: 'Cómo viaja', required: false, synonyms: ['como viaja', 'medio', 'integracion'] },
        { key: 'frequency', label: 'Frecuencia', required: false, synonyms: ['frecuencia', 'cada cuanto'] },
        { key: 'on_failure', label: 'Qué pasa si falla', required: false, synonyms: ['que pasa si falla', 'si falla', 'falla'] },
    ],
    procedure: [
        { key: 'steps', label: 'Pasos numerados', required: false, synonyms: ['pasos', 'pasos numerados', 'procedimiento'] },
        { key: 'who_can', label: 'Quién lo puede hacer', required: false, synonyms: ['quien lo puede hacer', 'quien lo hace', 'quien puede'] },
    ],
    glossary: [
        { key: 'term', label: 'Término', required: false, synonyms: ['termino', 'palabra'] },
        { key: 'definition', label: 'Definición', required: false, synonyms: ['definicion', 'significado'] },
        { key: 'also_called', label: 'También se dice', required: false, synonyms: ['tambien se dice', 'sinonimos', 'sinonimo'] },
    ],
};

/** Lowercase, no accents, no punctuation, single spaces: "Horario_de-envío" → "horario de envio". */
export function normalizeHeader(header: string): string {
    return header
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

function specsFor(type: KnowledgeType): FieldSpec[] {
    return [...COMMON, ...BY_TYPE[type]];
}

/** Fields a column can map to for this sheet type (common ones first). */
export function fieldsFor(type: KnowledgeType): ImportField[] {
    return specsFor(type).map(({ key, label, required }) => ({ key, label, required }));
}

/** First-come match of every header; unknown headers are skipped. */
export function autoMap(headers: string[], type: KnowledgeType, samples: string[] = []): ImportColumn[] {
    const specs = specsFor(type);
    const taken = new Set<string>();

    return headers.map((name, index) => {
        const normalized = normalizeHeader(name);
        const spec = specs.find((candidate) => !taken.has(candidate.key) && (normalizeHeader(candidate.label) === normalized || candidate.synonyms.includes(normalized)));
        const sample = samples[index] ?? '';
        if (!spec) return { name, sample, field: null, match: 'skip' };
        taken.add(spec.key);
        return { name, sample, field: spec.key, match: spec.key === 'type' ? 'fixed' : 'auto' };
    });
}

/**
 * The person picks a field for a column: it becomes "manual" (or "skip" with
 * null) and any other column holding that field is freed. The fixed type
 * column never changes.
 */
export function setColumnField(columns: ImportColumn[], columnName: string, field: string | null): ImportColumn[] {
    const target = columns.find((column) => column.name === columnName);
    if (!target || target.match === 'fixed') return columns;

    return columns.map((column) => {
        if (column.name === columnName) return { ...column, field, match: field === null ? 'skip' : 'manual' };
        if (field !== null && column.field === field && column.match !== 'fixed') return { ...column, field: null, match: 'skip' };
        return column;
    });
}

export type MappingSummary = Record<ImportMatchKind, number> & { total: number };

export function mappingSummary(columns: ImportColumn[]): MappingSummary {
    const summary: MappingSummary = { total: columns.length, auto: 0, manual: 0, fixed: 0, skip: 0 };
    for (const column of columns) summary[column.match] += 1;
    return summary;
}

/** Required fields that no column feeds yet. */
export function missingRequired(columns: ImportColumn[], type: KnowledgeType): ImportField[] {
    const mapped = new Set(columns.map((column) => column.field).filter((field): field is string => field !== null));
    return fieldsFor(type).filter((field) => field.required && !mapped.has(field.key));
}
