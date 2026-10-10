/**
 * Mocks for the Asistente TI curation pages: Excel import (C-ImportUpload,
 * C-ImportMapping, C-ImportPreview), /aprender drafts (C-Drafts,
 * C-DraftPhone) and unanswered questions (C-Unanswered). Every entity comes
 * from `assistant-fixtures.ts`. The upload limit is unknown and stays a
 * placeholder; the 6-month review default comes from the knowledge form.
 */
import { setColumnField } from '@/lib/importMapping';
import { filterPreviewRows } from '@/lib/importPreview';
import { sortUnanswered } from '@/lib/curation';
import type {
    DraftsIndexProps,
    ImportCreateProps,
    ImportMappingProps,
    ImportPreviewProps,
    UnansweredIndexProps,
} from '@/types/pages/assistant-curation';
import type { PageProps } from '@/types/shared';
import {
    ASSISTANT_TAB_COUNTS,
    cardRef,
    IMPORT_FIELDS,
    IMPORT_FILE,
    IMPORT_MAPPING,
    IMPORT_ROWS,
    IMPORT_SUMMARY,
    KNOWLEDGE_CARDS,
    LEARN_DRAFTS,
    NOW_ISO,
    UNANSWERED,
} from './assistant-fixtures';
import { sharedFor, type MockVariant } from './shared';

type CreatePage = PageProps<ImportCreateProps>;
type MappingPage = PageProps<ImportMappingProps>;
type PreviewPage = PageProps<ImportPreviewProps>;
type DraftsPage = PageProps<DraftsIndexProps>;
type UnansweredPage = PageProps<UnansweredIndexProps>;

const counts = { drafts_count: ASSISTANT_TAB_COUNTS.drafts, unanswered_count: ASSISTANT_TAB_COUNTS.unanswered };

// ---- import -------------------------------------------------------------------

const uploadEmpty: CreatePage = { ...sharedFor('jhon'), ...counts, file: null, max_size_label: '[TAMAÑO]', now: NOW_ISO };

const mapping: MappingPage = {
    ...sharedFor('jhon'),
    ...counts,
    file: IMPORT_FILE,
    mapping: IMPORT_MAPPING,
    fields: IMPORT_FIELDS,
    review_months: 6,
};

const ERROR_ROWS = IMPORT_ROWS.filter((row) => row.outcome === 'error').map((row) => row.row);

const preview: PreviewPage = {
    ...sharedFor('jhon'),
    ...counts,
    file: IMPORT_FILE,
    type: IMPORT_MAPPING.type,
    summary: IMPORT_SUMMARY,
    filter: 'all',
    rows: IMPORT_ROWS,
    error_rows: ERROR_ROWS,
};

// ---- drafts ---------------------------------------------------------------------

const drafts: DraftsPage = {
    ...sharedFor('jhon'),
    ...counts,
    drafts: LEARN_DRAFTS,
    selected: LEARN_DRAFTS[0] ?? null,
    requested: false,
    opened_from: null,
    now: NOW_ISO,
};

// ---- unanswered -------------------------------------------------------------------

const unanswered: UnansweredPage = {
    ...sharedFor('jhon'),
    ...counts,
    questions: sortUnanswered(UNANSWERED, 'times'),
    order: 'times',
    card_options: KNOWLEDGE_CARDS.map(cardRef),
    now: NOW_ISO,
};

export const variants: Record<string, MockVariant<CreatePage | MappingPage | PreviewPage | DraftsPage | UnansweredPage>> = {
    import_upload_empty: {
        page: 'Assistant/Import/Create',
        label: 'Importar · paso 1 sin archivo',
        props: uploadEmpty,
    },
    import_upload_file: {
        page: 'Assistant/Import/Create',
        label: 'Importar · paso 1 con mapa-de-sistemas.xlsx leído (hoja Informes, 11 columnas)',
        props: { ...uploadEmpty, file: IMPORT_FILE },
    },
    import_mapping: {
        page: 'Assistant/Import/Mapping',
        label: 'Importar · paso 2 columnas (8 automáticas, Destinatarios a mano, Notas no se importa)',
        props: mapping,
    },
    import_mapping_missing: {
        page: 'Assistant/Import/Mapping',
        label: 'Importar · paso 2 con Empresa sin columna (bloquea la vista previa)',
        props: { ...mapping, mapping: { ...IMPORT_MAPPING, columns: setColumnField(IMPORT_MAPPING.columns, 'Empresa', null) } },
    },
    import_preview: {
        page: 'Assistant/Import/Preview',
        label: 'Importar · paso 3 vista previa (28 nuevas, 3 actualizan, 1 con error)',
        props: preview,
    },
    import_preview_errors_only: {
        page: 'Assistant/Import/Preview',
        label: 'Importar · paso 3 filtrando “Con error”',
        props: { ...preview, filter: 'error', rows: filterPreviewRows(IMPORT_ROWS, 'error') },
    },
    drafts: {
        page: 'Assistant/Drafts/Index',
        label: 'Borradores de /aprender · 3 pendientes, el primero se parece a K-031',
        props: drafts,
    },
    drafts_phone: {
        page: 'Assistant/Drafts/Index',
        label: 'Teléfono (C-DraftPhone) · Leonardo abre el borrador desde Telegram · ver a 390 px',
        props: { ...drafts, ...sharedFor('leonardo'), requested: true, opened_from: 'telegram' },
    },
    drafts_empty: {
        page: 'Assistant/Drafts/Index',
        label: 'Borradores · ninguno pendiente',
        props: { ...drafts, drafts: [], selected: null, drafts_count: 0 },
    },
    unanswered: {
        page: 'Assistant/Unanswered/Index',
        label: 'Preguntas sin respuesta · 7, las más preguntadas primero',
        props: unanswered,
    },
    unanswered_empty: {
        page: 'Assistant/Unanswered/Index',
        label: 'Preguntas sin respuesta · todo documentado',
        props: { ...unanswered, questions: [], unanswered_count: 0 },
    },
    no_permission: {
        page: 'Assistant/Import/Create',
        label: 'Sin permiso de la base de conocimiento · Paola Ríos',
        props: { ...uploadEmpty, ...sharedFor('paola'), drafts_count: 0, unanswered_count: 0 },
    },
};
