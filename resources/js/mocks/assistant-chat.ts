/**
 * Mocks for the Asistente TI chat (boards C-ChatPhone, C-ChatDesktop,
 * C-ChatNoAnswer, C-ChatPerson, C-ChatPassword, C-ChatStates). Today only
 * Jhon Castro and Leonardo Herrera hold `assistant.use`; the "off for others"
 * and "refused" states use a generic placeholder user, not a real person.
 * The revealed password is the obviously fake board sample.
 */
import type { ChatConversation, ChatConversationSummary } from '@/types/assistant';
import type { AssistantChatProps, ChatSourcePreview } from '@/types/pages/assistant-chat';
import type { AuthUser, PageProps, Permission, SharedProps } from '@/types/shared';
import {
    ASSISTANT_HOLDERS,
    ASSISTANT_TAB_COUNTS,
    CARD_K031,
    cardSummary,
    CONVERSATION_BACKUP,
    CONVERSATION_ERROR,
    CONVERSATION_PASSWORD,
    CONVERSATION_PERSON,
    CONVERSATION_REFUSED,
    CONVERSATION_SALES,
    CONVERSATION_SEARCHING,
    CONVERSATIONS,
    FIRST_OPEN_SUGGESTIONS,
    NOW_ISO,
    REVEALED_CREDENTIAL_SAMPLE,
} from './assistant-fixtures';
import { sharedFor, type MockVariant } from './shared';

type ChatPage = PageProps<AssistantChatProps>;

/**
 * Hypothetical user with `assistant.use` but neither knowledge management nor
 * credentials (nobody outside IT has the assistant today).
 */
const GENERIC_USER: AuthUser = {
    id: 90,
    name: '[USUARIO CON ASISTENTE]',
    email: '[CORREO]',
    avatar_url: null,
    company_id: 1,
    roles: ['[ROL]'],
    permissions: ['assistant.use' satisfies Permission],
};

function genericShared(): SharedProps {
    const base = sharedFor('leonardo');
    return { ...base, auth: { user: GENERIC_USER }, current_company_id: GENERIC_USER.company_id, notifications_unread: 1 };
}

const SOURCE_K031: ChatSourcePreview = { ...cardSummary(CARD_K031), description: CARD_K031.description };

/** The password conversation is the newest one of the morning in its own variants. */
const withTitle = (conversation: ChatConversation, title: string): ChatConversation => ({ ...conversation, title });
const summaryOf = (conversation: ChatConversation): ChatConversationSummary => ({
    id: conversation.id,
    title: conversation.title,
    last_message_at: conversation.messages[conversation.messages.length - 1]?.at ?? conversation.last_message_at,
});

const PERSON = withTitle(CONVERSATION_PERSON, 'Contraseña de Office de Juan Pérez');
const PASSWORD = withTitle(CONVERSATION_PASSWORD, 'Contraseña de Office de Juan Pérez');
const REFUSED = withTitle(CONVERSATION_REFUSED, 'Contraseña de Office de Juan Pérez');

const jhon: ChatPage = {
    ...sharedFor('jhon'),
    status: 'ready',
    monthly_cap: null,
    conversations: CONVERSATIONS,
    conversation: CONVERSATION_SALES,
    selected_source: SOURCE_K031,
    revealed_credential: null,
    first_open_suggestions: FIRST_OPEN_SUGGESTIONS,
    unanswered_count: ASSISTANT_TAB_COUNTS.unanswered,
    drafts_count: ASSISTANT_TAB_COUNTS.drafts,
    can_configure_ai: true,
    access_holders: [],
    now: NOW_ISO,
};

const generic: ChatPage = {
    ...jhon,
    ...genericShared(),
    conversations: [],
    conversation: null,
    selected_source: null,
    unanswered_count: 0,
    drafts_count: 0,
    can_configure_ai: false,
};

export const variants: Record<string, MockVariant<ChatPage>> = {
    conversation: {
        page: 'Assistant/Chat',
        label: 'Conversación con fuentes · Jhon (escritorio con vista previa de K-031)',
        props: jhon,
    },
    no_answer: {
        page: 'Assistant/Chat',
        label: 'No lo tengo documentado · enlace a preguntas sin respuesta',
        props: { ...jhon, conversation: CONVERSATION_BACKUP, selected_source: null },
    },
    person_choice: {
        page: 'Assistant/Chat',
        label: 'Contraseña · elegir entre dos Juan Pérez',
        props: { ...jhon, conversations: [summaryOf(PERSON), ...CONVERSATIONS], conversation: PERSON, selected_source: null },
    },
    credential_hidden: {
        page: 'Assistant/Chat',
        label: 'Contraseña entregada por el Gestor TI · oculta, y enlace a la bóveda',
        props: { ...jhon, conversations: [summaryOf(PASSWORD), ...CONVERSATIONS], conversation: PASSWORD, selected_source: null },
    },
    credential_revealed: {
        page: 'Assistant/Chat',
        label: 'Contraseña visible 30 s con cuenta regresiva · enlace a la bóveda debajo',
        props: {
            ...jhon,
            conversations: [summaryOf(PASSWORD), ...CONVERSATIONS],
            conversation: PASSWORD,
            selected_source: null,
            revealed_credential: REVEALED_CREDENTIAL_SAMPLE,
        },
    },
    first_open: {
        page: 'Assistant/Chat',
        label: 'Primera vez · saludo y 4 preguntas sugeridas',
        props: { ...jhon, conversations: [], conversation: null, selected_source: null },
    },
    searching: {
        page: 'Assistant/Chat',
        label: 'Buscando en la base de conocimiento · composer en espera',
        props: { ...jhon, conversation: CONVERSATION_SEARCHING, selected_source: null },
    },
    unavailable: {
        page: 'Assistant/Chat',
        label: 'Asistente no disponible · falla del proveedor, Reintentar',
        props: { ...jhon, status: 'unavailable', conversation: CONVERSATION_ERROR, selected_source: null },
    },
    off_admin: {
        page: 'Assistant/Chat',
        label: 'Asistente apagado · tope mensual, vista de administrador',
        props: { ...jhon, status: 'off_cap', monthly_cap: 'US$ 10', conversation: null, selected_source: null },
    },
    off_user: {
        page: 'Assistant/Chat',
        label: 'Asistente apagado · lo que ve un usuario sin administración (genérico)',
        props: { ...generic, status: 'off_cap' },
    },
    refused: {
        page: 'Assistant/Chat',
        label: 'Contraseña negada · usuario sin permiso de credenciales (genérico)',
        props: { ...generic, conversations: [summaryOf(REFUSED)], conversation: REFUSED },
    },
    no_permission: {
        page: 'Assistant/Chat',
        label: 'Sin permiso para el Asistente · Andrés Méndez',
        props: {
            ...generic,
            ...sharedFor('andres'),
            access_holders: ASSISTANT_HOLDERS,
        },
    },
};
