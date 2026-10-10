import { Link } from '@inertiajs/react';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { conversationGroups, conversationWhen } from '@/lib/chat';
import { route } from '@/lib/route';
import type { ChatConversationSummary } from '@/types/assistant';

export interface ConversationListProps {
    conversations: ChatConversationSummary[];
    currentId: number | null;
    now: Date;
    /** "Nueva conversación" (`assistant.conversations.store`). */
    onNew: () => void;
    /** Hides the button while the assistant is off or a request is in flight. */
    newDisabled?: boolean;
    /** Called after picking one (closes the drawer). */
    onNavigate?: () => void;
    className?: string;
}

/** Recent conversations grouped "Hoy · Esta semana · Antes", with "Nueva conversación" on top. */
export function ConversationList({ conversations, currentId, now, onNew, newDisabled = false, onNavigate, className }: ConversationListProps) {
    const groups = conversationGroups(conversations, now);

    return (
        <div className={cx('flex min-h-0 flex-col gap-1', className)}>
            <button
                type="button"
                onClick={onNew}
                disabled={newDisabled}
                className="mb-2.5 inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-control bg-brand-button text-sm font-bold text-brand-button-ink transition-[filter] hover:brightness-[0.93] disabled:cursor-not-allowed disabled:bg-line disabled:text-fg-muted"
            >
                <Icon name="plus" size={16} />
                Nueva conversación
            </button>
            {groups.length === 0 ? <p className="px-2 py-1 text-[13px] text-fg-muted">Aún no tienes conversaciones.</p> : null}
            <div className="flex min-h-0 flex-col gap-1 overflow-y-auto">
                {groups.map((group, index) => (
                    <section key={group.label} aria-label={group.label} className="flex flex-col gap-1">
                        <h2 className={cx('px-2 pb-1 text-xs font-bold text-fg-muted', index === 0 ? 'pt-1' : 'pt-3')}>{group.label}</h2>
                        <ul className="flex flex-col gap-1">
                            {group.items.map((item) => {
                                const current = item.id === currentId;
                                return (
                                    <li key={item.id}>
                                        <Link
                                            href={route('assistant.index', { conversation: item.id })}
                                            aria-current={current ? 'page' : undefined}
                                            onClick={onNavigate}
                                            className={cx(
                                                'flex min-h-[52px] flex-col justify-center gap-0.5 rounded-control px-2.5 py-1.5 transition-colors duration-150',
                                                current ? 'bg-brand-tint text-brand-ink' : 'text-fg hover:bg-subtle',
                                            )}
                                        >
                                            <b className="truncate text-sm">{item.title}</b>
                                            <span className="text-xs text-fg-muted">{conversationWhen(item.last_message_at, now)}</span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                ))}
            </div>
        </div>
    );
}
