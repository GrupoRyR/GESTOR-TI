import type { ChatMessage, ChatSource, PersonOption, RevealedCredential } from '@/types/assistant';
import type { Company } from '@/types/shared';
import { AssistantMessage, NoAnswerMessage, VaultLinkCard } from './AssistantMessage';
import { AssistantErrorMessage, RefusedMessage, UserMessage } from './ChatBubbles';
import { CredentialCard } from './CredentialCard';
import { PersonPicker } from './PersonPicker';
import type { SourceLayout, SuggestionLayout } from './SourceList';

export interface ChatMessageHandlers {
    onSuggestion?: (text: string) => void;
    onPickPerson?: (message: ChatMessage, option: PersonOption) => void;
    onNoPerson?: (message: ChatMessage) => void;
    onRevealCredential?: (message: ChatMessage) => void;
    onCredentialCopied?: (message: ChatMessage) => void;
    onRetry?: (message: ChatMessage) => void;
    onSelectSource?: (source: ChatSource) => void;
}

export interface ChatMessageItemProps extends ChatMessageHandlers {
    message: ChatMessage;
    /** Suggestions show only under the last assistant message. */
    isLast?: boolean;
    /** `shared.companies` (person picker marks). */
    companies: Company[];
    revealedCredential?: RevealedCredential | null;
    /** Id of the credential message whose reveal is in flight. */
    revealingMessageId?: number | null;
    /** A question or pick is being sent: interactive answers turn off. */
    busy?: boolean;
    selectedSourceKey?: string | null;
    sourceLayout?: SourceLayout;
    suggestionLayout?: SuggestionLayout;
    /** Managers only: link + count for "Ver preguntas sin respuesta". */
    unansweredHref?: string | null;
    unansweredCount?: number;
}

/** Renders one message of any kind with the matching component. */
export function ChatMessageItem({
    message,
    isLast = false,
    companies,
    revealedCredential = null,
    revealingMessageId = null,
    busy = false,
    selectedSourceKey = null,
    sourceLayout,
    suggestionLayout,
    unansweredHref = null,
    unansweredCount,
    onSuggestion,
    onPickPerson,
    onNoPerson,
    onRevealCredential,
    onCredentialCopied,
    onRetry,
    onSelectSource,
}: ChatMessageItemProps) {
    switch (message.kind) {
        case 'text':
            return <UserMessage text={message.text} at={message.at} />;
        case 'answer':
            return (
                <AssistantMessage
                    message={message}
                    sourceLayout={sourceLayout}
                    suggestionLayout={suggestionLayout}
                    selectedSourceKey={selectedSourceKey}
                    onSelectSource={onSelectSource}
                    onSuggestion={onSuggestion}
                    showSuggestions={isLast}
                    disabled={busy}
                />
            );
        case 'no_answer':
            return (
                <NoAnswerMessage
                    message={message}
                    unansweredHref={unansweredHref}
                    unansweredCount={unansweredCount}
                    onSuggestion={onSuggestion}
                    showSuggestions={isLast}
                    suggestionLayout={suggestionLayout}
                    disabled={busy}
                />
            );
        case 'person_choice':
            return (
                <PersonPicker
                    message={message}
                    companies={companies}
                    disabled={busy}
                    onPick={(option) => onPickPerson?.(message, option)}
                    onNone={onNoPerson ? () => onNoPerson(message) : undefined}
                />
            );
        case 'credential_notice':
            return (
                <CredentialCard
                    notice={message}
                    revealed={revealedCredential}
                    revealing={revealingMessageId === message.id}
                    onReveal={() => onRevealCredential?.(message)}
                    onCopied={onCredentialCopied ? () => onCredentialCopied(message) : undefined}
                />
            );
        case 'vault_link':
            return <VaultLinkCard message={message} />;
        case 'refused':
            return <RefusedMessage text={message.text} at={message.at} />;
        case 'error':
            return <AssistantErrorMessage text={message.text} onRetry={message.retryable && onRetry ? () => onRetry(message) : undefined} retrying={busy} />;
    }
}
