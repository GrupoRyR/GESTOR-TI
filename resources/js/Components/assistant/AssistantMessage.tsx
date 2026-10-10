import { Link } from '@inertiajs/react';
import { Icon } from '@/Components/ui/Icon';
import type { AnswerMessage, ChatSource, NoAnswerMessage as NoAnswerData, VaultLinkMessage } from '@/types/assistant';
import { route } from '@/lib/route';
import { AssistantBubble, AssistantText } from './ChatBubbles';
import { SourceList, SuggestionChips, type SourceLayout, type SuggestionLayout } from './SourceList';

export interface AssistantMessageProps {
    message: AnswerMessage;
    sourceLayout?: SourceLayout;
    suggestionLayout?: SuggestionLayout;
    /** Desktop source preview. */
    selectedSourceKey?: string | null;
    onSelectSource?: (source: ChatSource) => void;
    /** Send a suggestion as a new question; suggestions hide without it. */
    onSuggestion?: (text: string) => void;
    /** Suggestions only under the last answer. */
    showSuggestions?: boolean;
    disabled?: boolean;
}

/** Normal answer: text, "Fuentes" (always) and, below, suggested questions. */
export function AssistantMessage({
    message,
    sourceLayout = 'responsive',
    suggestionLayout = 'responsive',
    selectedSourceKey = null,
    onSelectSource,
    onSuggestion,
    showSuggestions = true,
    disabled = false,
}: AssistantMessageProps) {
    return (
        <>
            <AssistantBubble label="Respuesta del Asistente TI" at={message.at}>
                <AssistantText text={message.text} />
                <SourceList sources={message.sources} layout={sourceLayout} selectedKey={selectedSourceKey} onSelect={onSelectSource} />
            </AssistantBubble>
            {showSuggestions && onSuggestion ? (
                <SuggestionChips suggestions={message.suggestions} onPick={onSuggestion} layout={suggestionLayout} disabled={disabled} className="md:mt-1" />
            ) : null}
        </>
    );
}

export interface NoAnswerMessageProps {
    message: NoAnswerData;
    /** Link to Preguntas sin respuesta; only for `assistant.knowledge.manage`. */
    unansweredHref?: string | null;
    unansweredCount?: number;
    onSuggestion?: (text: string) => void;
    showSuggestions?: boolean;
    suggestionLayout?: SuggestionLayout;
    disabled?: boolean;
}

/** "No lo tengo documentado." The question was registered; managers get a link to the list. */
export function NoAnswerMessage({
    message,
    unansweredHref = null,
    unansweredCount,
    onSuggestion,
    showSuggestions = true,
    suggestionLayout = 'responsive',
    disabled = false,
}: NoAnswerMessageProps) {
    const times = message.times_asked;
    return (
        <>
            <AssistantBubble label="Respuesta del Asistente TI: no documentado" tone="warning" at={message.at}>
                <div className="flex items-start gap-3">
                    <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-chip bg-status-maintenance-bg text-status-maintenance">
                        <Icon name="question" size={20} />
                    </span>
                    <div className="flex min-w-0 flex-col gap-1">
                        <AssistantText text={message.text} />
                        {message.explanation ? <p className="text-[13px] leading-[1.4] text-fg-muted">{message.explanation}</p> : null}
                    </div>
                </div>
                <p className="flex items-center gap-2 rounded-control bg-ground px-3 py-2.5 text-[13px] text-fg-soft">
                    <Icon name="inbox" size={16} />
                    <span>
                        Pregunta registrada
                        {times > 1 ? (
                            <>
                                {' · ya van '}
                                <b className="text-fg">{times} veces</b>
                            </>
                        ) : null}
                    </span>
                </p>
                <SourceList sources={[]} />
                {unansweredHref ? (
                    <Link
                        href={unansweredHref}
                        className="flex min-h-11 items-center justify-between gap-2 rounded-control border border-line-strong px-3 py-1.5 text-sm font-bold text-fg hover:bg-subtle"
                    >
                        <span className="flex flex-col">
                            <span>Ver preguntas sin respuesta</span>
                            <span className="text-xs font-medium text-fg-muted">Lo ves porque gestionas la base de conocimiento</span>
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            {unansweredCount !== undefined && unansweredCount > 0 ? (
                                <span className="tabular inline-flex h-[22px] min-w-[22px] items-center justify-center rounded-full bg-subtle px-1.5 text-xs font-bold">
                                    {unansweredCount}
                                    <span className="sr-only"> preguntas</span>
                                </span>
                            ) : null}
                            <Icon name="chevron-right" size={18} />
                        </span>
                    </Link>
                ) : null}
            </AssistantBubble>
            {showSuggestions && onSuggestion ? (
                <SuggestionChips
                    suggestions={message.suggestions}
                    onPick={onSuggestion}
                    title={message.suggestions_label ?? 'También puedes preguntar'}
                    layout={suggestionLayout}
                    disabled={disabled}
                />
            ) : null}
        </>
    );
}

export interface VaultLinkCardProps {
    message: VaultLinkMessage;
    /** Defaults to the vault item (`ai.vault.index` with `item`), which asks to confirm identity. */
    href?: string;
}

/** The chat never delivers vault credentials: it links to the item in the Bóveda. */
export function VaultLinkCard({ message, href }: VaultLinkCardProps) {
    const { item } = message;
    return (
        <AssistantBubble label="Respuesta del Asistente TI: credencial en la bóveda" at={message.at}>
            <AssistantText text={message.text} />
            <Link href={href ?? route('ai.vault.index', { item: item.id })} className="vault-zone flex flex-col overflow-hidden rounded-control text-fg">
                <span aria-hidden="true" className="vault-stripe h-[5px]" />
                <span className="flex min-h-12 items-center gap-2.5 px-3 py-1.5">
                    <Icon name="lock" size={18} className="text-status-maintenance" />
                    <span className="flex min-w-0 flex-1 flex-col">
                        <b className="text-sm text-status-maintenance">Abrir en la bóveda</b>
                        <span className="truncate text-xs text-fg-muted">
                            {item.name} · {item.product} · {item.environment_label}
                        </span>
                    </span>
                    <Icon name="chevron-right" size={18} />
                </span>
            </Link>
        </AssistantBubble>
    );
}
