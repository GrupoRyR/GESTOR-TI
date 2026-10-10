/**
 * Asistente TI · curation of the knowledge base (boards C-ImportUpload,
 * C-ImportMapping, C-ImportPreview, C-Drafts, C-DraftPhone, C-Unanswered).
 * Desktop-first; the draft approval is phone-first too (it opens from the
 * Telegram button at 390 px).
 *
 * Inertia components and their props (AssistantLayout):
 * - `Assistant/Import/Create`      ImportCreateProps      tab "import", step 1 C-ImportUpload (empty or file read)
 * - `Assistant/Import/Mapping`     ImportMappingProps     tab "import", step 2 C-ImportMapping
 * - `Assistant/Import/Preview`     ImportPreviewProps     tab "import", step 3 C-ImportPreview
 * - `Assistant/Drafts/Index`       DraftsIndexProps       tab "drafts", C-Drafts (list + detail) and C-DraftPhone
 * - `Assistant/Unanswered/Index`   UnansweredIndexProps   tab "unanswered", C-Unanswered
 *
 * Ziggy routes used:
 * - `assistant.import.create`          GET     —                                    step 1; the server sends the person's
 *                                                                                    unfinished import as `file`, if any.
 * - `assistant.import.template`        GET     —                                    download "plantilla de importación" (.xlsx).
 * - `assistant.import.upload`          POST    multipart `ImportUploadPayload`      `useForm` with `forceFormData`; the
 *                                                                                    server stores the file, reads its sheets and
 *                                                                                    redirects back to `.create` with `file`.
 *                                                                                    Errors on `file` (type, size, unreadable).
 * - `assistant.import.update`          PUT     `{ import: id }` body `ImportSheetPayload`  "Seguir con las columnas": sheet and
 *                                                                                    header row; redirects to `.mapping`.
 * - `assistant.import.destroy`         DELETE  `{ import: id }`                     "Quitar el archivo" and "Cancelar" with a
 *                                                                                    file; redirects to `.create` (empty).
 * - `assistant.import.mapping`         GET     `{ import: id }`                     step 2.
 * - `assistant.import.mapping.update`  PUT     `{ import: id }` body `ImportMappingPayload`  "Ver la vista previa"; errors on
 *                                                                                    `columns` (required field unmapped);
 *                                                                                    redirects to `.preview`.
 * - `assistant.import.preview`         GET     `{ import: id }` query `{ resultado?: ImportRowOutcome }`  step 3; the
 *                                                                                    summary tiles reload `only: ['rows', 'filter']`
 *                                                                                    with preserveState/preserveScroll/replace.
 * - `assistant.import.errors`          GET     `{ import: id }`                     "Descargar errores" (.xlsx with the error
 *                                                                                    rows and a column explaining each fix).
 * - `assistant.import.store`           POST    `{ import: id }`                     "Importar 31 fichas" after ConfirmDialog;
 *                                                                                    skips the error rows, redirects to
 *                                                                                    `assistant.knowledge.index` with flash success
 *                                                                                    "Se importaron 31 fichas: 28 nuevas y 3
 *                                                                                    actualizadas. La fila 17 se saltó."
 * - `assistant.drafts.index`           GET     query `{ borrador?: draft id, desde?: 'telegram' }`  selecting a draft
 *                                                                                    keeps the list (`only: ['selected', 'requested']`).
 *                                                                                    The Telegram button opens
 *                                                                                    `?borrador=ID&desde=telegram`.
 * - `assistant.drafts.approve`         POST    `{ draft: id }` body `DraftApprovePayload`  publishes the card; flash
 *                                                                                    "Ficha K-0NN publicada"; next draft selected.
 * - `assistant.drafts.merge`           POST    `{ draft: id, card: key }`           "Agregar a K-031": adds the new facts
 *                                                                                    to that card; flash; redirect to the card.
 * - `assistant.drafts.discard`         POST    `{ draft: id }` body `{ reason: string }` (required)  Dialog "Descartar".
 * - `assistant.knowledge.create`       GET     query `{ borrador: id }` / `{ pregunta: id }`  "Editar antes de aprobar",
 *                                                                                    "Documentar" (prefilled form, T3).
 * - `assistant.knowledge.show`         GET     `{ card: key }`                      "Ver K-031", linked card.
 * - `assistant.unanswered.index`       GET     query `{ orden?: 'recientes' }`      absent = most asked first.
 * - `assistant.unanswered.link`        POST    `{ question: id }` body `{ card: key }`  "Ya está documentada" Dialog; the
 *                                                                                    question leaves the list.
 * - `assistant.unanswered.discard`     POST    `{ question: id }`                   icon button + ConfirmDialog.
 *
 * Permissions (checked with `can()`; the server is the authority):
 * - `assistant.knowledge.manage`  every page here; without it the page shows NoPermission (and the tabs hide).
 * - Never put passwords in the Excel or a card: credentials live in Accesos or the Bóveda (`ai.vault.access`).
 */
import type {
    ImportField,
    ImportFile,
    ImportMapping,
    ImportRowResult,
    ImportSummary,
    KnowledgeCardRef,
    KnowledgeType,
    KnowledgeVisibility,
    LearnDraft,
    UnansweredQuestion,
} from '@/types/assistant';
import type { FormErrors } from '@/types/shared';
import type { ImportPreviewFilter } from '@/lib/importPreview';
import type { UnansweredOrder } from '@/lib/curation';

export type { ImportPreviewFilter, UnansweredOrder };

/** Tab counters every curation page sends ("Borradores de /aprender (3)"). */
interface CurationCounts {
    drafts_count: number;
    unanswered_count: number;
}

// ---- import -----------------------------------------------------------------

/** Body of `assistant.import.upload` (multipart). Payloads used by `useForm` are type aliases (interfaces fail its constraint). */
export type ImportUploadPayload = {
    file: File | null;
};

/** Body of `assistant.import.update`. */
export interface ImportSheetPayload {
    sheet: string;
    header_row: boolean;
}

export interface ImportCreateProps extends CurationCounts {
    /** The uploaded file once read; null before uploading. */
    file: ImportFile | null;
    /** Upload limit as shown ("10 MB"). */
    max_size_label: string;
    /** Server time, ISO 8601 ("Subido hoy 9:40 a. m."). */
    now: string;
    errors?: FormErrors;
}

/** Body of `assistant.import.mapping.update`: each Excel column and its field (null = not imported). */
export interface ImportMappingPayload {
    columns: { name: string; field: string | null }[];
}

export interface ImportMappingProps extends CurationCounts {
    file: ImportFile;
    /** Columns of the selected sheet as matched by the server (see `lib/importMapping.ts`). */
    mapping: ImportMapping;
    /** Fields of `mapping.type` a column can feed (`fieldsFor(type)`). */
    fields: ImportField[];
    /** The Excel has no review date: every card gets today + this many months. */
    review_months: number;
    errors?: FormErrors;
}

export interface ImportPreviewProps extends CurationCounts {
    file: ImportFile;
    /** Sheet type: every row enters as this type. */
    type: KnowledgeType;
    /** Counts of the whole sheet (tiles and buttons). */
    summary: ImportSummary;
    filter: ImportPreviewFilter;
    /** Rows of the filter worth reviewing: errors and updates first, then a sample of the new ones. */
    rows: ImportRowResult[];
    /** Excel rows that will be skipped (all of them, not only those shown). */
    error_rows: number[];
}

// ---- /aprender drafts -------------------------------------------------------

/** Body of `assistant.drafts.approve`: the two values the panel lets you change. */
export type DraftApprovePayload = {
    visibility: KnowledgeVisibility;
    /** `YYYY-MM-DD`. */
    review_at: string;
};

export type DraftDiscardPayload = {
    reason: string;
};

export interface DraftsIndexProps extends CurationCounts {
    /** Pending drafts, most recent first. */
    drafts: LearnDraft[];
    /** The `?borrador=` draft, else the first one; null when there are none. */
    selected: LearnDraft | null;
    /**
     * True when the URL named the draft (`?borrador=`). On phone the page then
     * opens on the detail (with a back link) instead of the list.
     */
    requested: boolean;
    /** `?desde=telegram`: the phone header says "Desde Telegram". */
    opened_from: 'telegram' | null;
    /** Server time, ISO 8601 (relative dates). */
    now: string;
    errors?: FormErrors;
}

// ---- unanswered questions ---------------------------------------------------

export interface UnansweredIndexProps extends CurationCounts {
    /** Already sorted by `order`. */
    questions: UnansweredQuestion[];
    order: UnansweredOrder;
    /** Cards to pick in "Ya está documentada" (title + key). */
    card_options: KnowledgeCardRef[];
    now: string;
}
