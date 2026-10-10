import { router } from '@inertiajs/react';
import { useState } from 'react';
import { AssistantPhoneHeader, AssistantTile, ChatLog, DayDivider, TypingIndicator, UserMessage } from '@/Components/assistant/ChatBubbles';
import { ChatMessageItem } from '@/Components/assistant/ChatMessageItem';
import { Composer } from '@/Components/assistant/Composer';
import { AssistantOffNotice, ChatGreeting } from '@/Components/assistant/chat/ChatStates';
import { ConversationList } from '@/Components/assistant/chat/ConversationList';
import { SourcePreview } from '@/Components/assistant/chat/SourcePreview';
import { useMediaQuery } from '@/Components/assistant/chat/useMediaQuery';
import { Drawer } from '@/Components/ui/Drawer';
import { IconButton } from '@/Components/ui/IconButton';
import { NoPermission } from '@/Components/ui/NoPermission';
import { cx } from '@/Components/ui/utils';
import { AssistantLayout } from '@/Layouts/AssistantLayout';
import { can } from '@/lib/can';
import { conversationWhen, groupByDay, pendingQuestion } from '@/lib/chat';
import { bogotaDateKey } from '@/lib/format';
import { route } from '@/lib/route';
import type { ChatMessage, ChatSource, PersonOption } from '@/types/assistant';
import type { AssistantChatProps } from '@/types/pages/assistant-chat';
import type { PageProps } from '@/types/shared';

const TITLE = 'Asistente TI';
const READ_ONLY = 'solo consulta, el asistente no cambia nada';
/** Three columns (list · thread · preview) from here; below, sources open the card page. */
const PREVIEW_QUERY = '(min-width: 1280px)';

function joinNames(names: string[]): string {
    if (names.length <= 1) return names[0] ?? '';
    return `${names.slice(0, -1).join(', ')} y ${names[names.length - 1]}`;
}

export default function Chat(props: PageProps<AssistantChatProps>) {
    const {
        status,
        monthly_cap,
        conversations,
        conversation,
        selected_source,
        revealed_credential,
        first_open_suggestions,
        unanswered_count,
        drafts_count,
        can_configure_ai,
        access_holders,
        now: nowIso,
    } = props;
    const user = props.auth.user;
    const canUse = can(user, 'assistant.use');
    const canManage = can(user, 'assistant.knowledge.manage');
    const canReveal = can(user, 'assistant.credentials') && can(user, 'credentials.view');

    const now = new Date(nowIso);
    const today = bogotaDateKey(nowIso);
    const wide = useMediaQuery(PREVIEW_QUERY);

    const [question, setQuestion] = useState('');
    const [questionError, setQuestionError] = useState<string | undefined>();
    /** The question being sent, shown right away until the server answers. */
    const [sending, setSending] = useState<{ text: string; at: string } | null>(null);
    /** Any chat request in flight (question, pick, retry). */
    const [busy, setBusy] = useState(false);
    const [revealingId, setRevealingId] = useState<number | null>(null);
    const [listOpen, setListOpen] = useState(false);

    const counts = canManage ? { drafts: drafts_count, unanswered: unanswered_count } : undefined;
    const phoneHeaderBase = { title: TITLE, subtitle: 'Solo consulta · no cambia nada' };

    if (!canUse) {
        const holders = joinNames(access_holders.map((person) => person.name));
        return (
            <AssistantLayout shared={props} title={TITLE} tab="chat" phoneHeader={<AssistantPhoneHeader {...phoneHeaderBase} />}>
                <NoPermission
                    title="No tienes acceso al Asistente TI"
                    description={holders ? `Hoy lo usan ${holders}. Si lo necesitas, pídeselo a TI.` : 'Si lo necesitas, pídeselo a TI.'}
                    className="mx-auto w-full max-w-[640px]"
                />
            </AssistantLayout>
        );
    }

    const off = status === 'off_no_key' || status === 'off_cap';
    const messages = conversation?.messages ?? [];
    const awaiting = pendingQuestion(messages) !== null;
    const waiting = busy || awaiting;
    const lastId = messages[messages.length - 1]?.id;
    const groups = groupByDay(messages, now);
    const conversationParams = conversation ? { conversation: conversation.id } : undefined;
    const showPreview = wide && selected_source !== null;

    const chatRequest = (onDone?: () => void) => ({
        preserveScroll: true,
        preserveState: true,
        onStart: () => setBusy(true),
        onFinish: () => {
            setBusy(false);
            onDone?.();
        },
    });

    const ask = (text: string) => {
        if (off || waiting) return;
        router.post(
            route('assistant.messages.store', conversationParams),
            { question: text },
            {
                ...chatRequest(() => setSending(null)),
                onStart: () => {
                    setBusy(true);
                    setQuestionError(undefined);
                    setSending({ text, at: new Date().toISOString() });
                },
                onSuccess: () => setQuestion(''),
                onError: (errors) => setQuestionError(errors.question),
            },
        );
    };

    const newConversation = () => {
        setListOpen(false);
        router.post(route('assistant.conversations.store'), {}, chatRequest());
    };

    const pickPerson = (message: ChatMessage, option: PersonOption | null) => {
        router.post(route('assistant.people.pick', { message: message.id }), { option: option ? option.id : null }, chatRequest());
    };

    const retry = (message: ChatMessage) => {
        router.post(route('assistant.messages.retry', { message: message.id }), {}, chatRequest());
    };

    const reveal = (message: ChatMessage) => {
        if (!canReveal) return;
        router.post(
            route('assistant.credentials.reveal', { message: message.id }),
            {},
            {
                only: ['revealed_credential'],
                preserveScroll: true,
                preserveState: true,
                onStart: () => setRevealingId(message.id),
                onFinish: () => setRevealingId(null),
            },
        );
    };

    const copied = (message: ChatMessage) => {
        router.post(route('assistant.credentials.copied', { message: message.id }), {}, { only: ['conversation'], preserveScroll: true, preserveState: true });
    };

    const previewVisit = (fuente: string | null) => {
        const params = { ...conversationParams, ...(fuente ? { fuente } : {}) };
        router.get(route('assistant.index', params), {}, { only: ['selected_source'], preserveState: true, preserveScroll: true, replace: true });
    };

    const placeholder = off
        ? can_configure_ai
            ? 'El asistente está apagado'
            : 'El asistente no está activo'
        : waiting
          ? 'Espera la respuesta'
          : undefined;

    const headerActions = (
        <>
            <IconButton icon="history" label="Conversaciones anteriores" onClick={() => setListOpen(true)} />
            <IconButton icon="plus" label="Nueva conversación" onClick={newConversation} disabled={waiting || off} />
        </>
    );

    const list = (onNavigate?: () => void) => (
        <ConversationList
            conversations={conversations}
            currentId={conversation?.id ?? null}
            now={now}
            onNew={newConversation}
            newDisabled={waiting || off}
            onNavigate={onNavigate}
            className="h-full"
        />
    );

    return (
        <AssistantLayout
            shared={props}
            title={conversation ? `${conversation.title} · ${TITLE}` : TITLE}
            tab="chat"
            counts={counts}
            phoneHeader={<AssistantPhoneHeader {...phoneHeaderBase} actions={headerActions} />}
            fullHeight
        >
            <div
                className={cx(
                    'flex min-h-0 flex-1 lg:grid lg:grid-cols-[272px_minmax(0,1fr)] lg:gap-5',
                    showPreview && 'xl:grid-cols-[240px_minmax(0,1fr)_300px] min-[1400px]:grid-cols-[272px_minmax(0,1fr)_340px]',
                )}
            >
                <nav aria-label="Conversaciones recientes" className="flex min-h-0 flex-col overflow-hidden rounded-card border border-line bg-surface p-3.5 max-lg:hidden">
                    {list()}
                </nav>

                <section aria-label="Conversación" className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden md:rounded-card md:border md:border-line md:bg-surface">
                    <header className="flex shrink-0 items-center gap-3 border-b border-line py-2 pr-2 pl-5 max-md:hidden lg:py-3.5 lg:pr-5">
                        <AssistantTile />
                        <span className="flex min-w-0 flex-col">
                            <h1 className="truncate font-display text-xl leading-tight font-bold tracking-[-0.02em]">{conversation?.title ?? 'Nueva conversación'}</h1>
                            <span className="truncate text-xs text-fg-muted">
                                {conversation ? `${conversationWhen(conversation.started_at, now)} · ${READ_ONLY}` : READ_ONLY.charAt(0).toUpperCase() + READ_ONLY.slice(1)}
                            </span>
                        </span>
                        <div className="ml-auto flex shrink-0 items-center lg:hidden">{headerActions}</div>
                    </header>

                    <ChatLog scrollKey={`${lastId ?? 0}-${sending ? 1 : 0}-${busy ? 1 : 0}`} busy={waiting} className="px-4 py-3.5 md:bg-ground/50 md:px-6 md:py-5">
                        {groups.map((group) => (
                            <div key={group.key} className="contents">
                                <DayDivider label={group.label} />
                                {group.items.map((message) => (
                                    <ChatMessageItem
                                        key={message.id}
                                        message={message}
                                        isLast={message.id === lastId && !waiting}
                                        companies={props.companies}
                                        revealedCredential={revealed_credential}
                                        revealingMessageId={revealingId}
                                        busy={waiting || off}
                                        selectedSourceKey={showPreview ? selected_source?.key : null}
                                        unansweredHref={canManage ? route('assistant.unanswered.index') : null}
                                        unansweredCount={canManage ? unanswered_count : undefined}
                                        onSuggestion={off ? undefined : ask}
                                        onPickPerson={(item, option) => pickPerson(item, option)}
                                        onNoPerson={(item) => pickPerson(item, null)}
                                        onRevealCredential={canReveal ? reveal : undefined}
                                        onCredentialCopied={copied}
                                        onRetry={retry}
                                        onSelectSource={wide ? (source: ChatSource) => previewVisit(source.key) : undefined}
                                    />
                                ))}
                            </div>
                        ))}
                        {sending ? <UserMessage text={sending.text} at={sending.at} /> : null}
                        {waiting ? <TypingIndicator /> : null}
                        {off ? <AssistantOffNotice reason={status} admin={can_configure_ai} monthlyCap={monthly_cap} /> : null}
                        {!off && messages.length === 0 && !sending ? (
                            <ChatGreeting firstName={user.name.split(' ')[0] ?? user.name} suggestions={first_open_suggestions} onPick={ask} disabled={waiting} />
                        ) : null}
                    </ChatLog>

                    <Composer
                        value={question}
                        onChange={(value) => {
                            setQuestion(value);
                            if (questionError) setQuestionError(undefined);
                        }}
                        onSubmit={ask}
                        disabled={off}
                        busy={waiting}
                        placeholder={placeholder}
                        error={questionError}
                        className="md:bg-surface"
                    />
                </section>

                {showPreview && selected_source ? (
                    <SourcePreview source={selected_source} companies={props.companies} today={today} onClose={() => previewVisit(null)} className="max-xl:hidden" />
                ) : null}
            </div>

            <Drawer open={listOpen} onClose={() => setListOpen(false)} title="Conversaciones" width={360}>
                {list(() => setListOpen(false))}
            </Drawer>
        </AssistantLayout>
    );
}
