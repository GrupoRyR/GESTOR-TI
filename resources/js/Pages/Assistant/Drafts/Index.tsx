import { AssistantPhoneHeader } from '@/Components/assistant/ChatBubbles';
import { CurationNoPermission } from '@/Components/assistant/curation/CurationParts';
import { DraftDetail, DraftListItem } from '@/Components/assistant/curation/DraftParts';
import { EmptyState } from '@/Components/ui/EmptyState';
import { cx } from '@/Components/ui/utils';
import { AssistantLayout } from '@/Layouts/AssistantLayout';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import type { DraftsIndexProps } from '@/types/pages/assistant-curation';
import type { PageProps } from '@/types/shared';

/**
 * Borradores de /aprender (C-Drafts, C-DraftPhone): pending list and the
 * selected draft. Desktop and tablet show both columns; on phone the list
 * and the detail are separate screens: `?borrador=` (`requested`) opens the
 * detail, which is how the Telegram button lands at 390 px.
 */
export default function DraftsIndex(props: PageProps<DraftsIndexProps>) {
    const { drafts, selected, requested, opened_from, drafts_count, unanswered_count, errors } = props;
    const user = props.auth.user;
    const manage = can(user, 'assistant.knowledge.manage');
    const now = new Date(props.now);
    const phoneDetail = requested && selected !== null;
    const pending = `${drafts.length} ${drafts.length === 1 ? 'pendiente' : 'pendientes'}`;

    const phoneHeader = !manage ? undefined : phoneDetail ? (
        <AssistantPhoneHeader
            title="Borrador de /aprender"
            subtitle={opened_from === 'telegram' ? 'Desde Telegram' : pending}
            back={{ href: route('assistant.drafts.index'), label: 'Ver todos los borradores' }}
        />
    ) : (
        <AssistantPhoneHeader title="Borradores de /aprender" subtitle={pending} back={{ href: route('assistant.index'), label: 'Volver al chat del Asistente' }} />
    );

    let content;
    if (!manage) {
        content = <CurationNoPermission what="Aprobar lo que enseñan con /aprender" />;
    } else {
        const position = selected ? drafts.findIndex((draft) => draft.id === selected.id) + 1 : 0;
        content = (
            <div className="flex min-w-0 flex-col gap-4">
                <div className={cx('flex flex-col gap-1', phoneDetail ? 'max-md:hidden' : 'max-md:sr-only')}>
                    <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[28px]">Borradores de /aprender</h1>
                    <p className="text-sm text-fg-soft">
                        Lo que le enseñan al asistente con /aprender llega aquí como borrador. Nada se publica sin aprobación.
                    </p>
                </div>

                {drafts.length === 0 || !selected ? (
                    <EmptyState
                        icon="check-circle"
                        title="No hay borradores pendientes"
                        description="Cuando alguien le enseñe algo al asistente con /aprender, por Telegram o en el chat, llega aquí para que lo revises antes de publicarlo."
                    />
                ) : (
                    <div className="flex flex-col gap-4 md:grid md:grid-cols-[260px_minmax(0,1fr)] md:items-start md:gap-5 lg:grid-cols-[320px_minmax(0,1fr)] xl:grid-cols-[372px_minmax(0,1fr)] xl:gap-6">
                        <section aria-label="Borradores pendientes" className={cx('flex flex-col gap-2.5', phoneDetail && 'max-md:hidden')}>
                            <span className="text-[13px] font-semibold text-fg-muted">{pending} · los más recientes primero</span>
                            <ul className="flex flex-col gap-2.5">
                                {drafts.map((draft) => (
                                    <li key={draft.id}>
                                        <DraftListItem draft={draft} current={draft.id === selected.id} now={now} />
                                    </li>
                                ))}
                            </ul>
                        </section>
                        <div className={cx('min-w-0', !phoneDetail && 'max-md:hidden')}>
                            <DraftDetail
                                key={selected.id}
                                draft={selected}
                                position={position}
                                total={drafts.length}
                                viewerName={user.name.split(' ')[0] ?? user.name}
                                viewerId={user.id}
                                now={now}
                                errors={errors}
                            />
                        </div>
                    </div>
                )}
            </div>
        );
    }

    return (
        <AssistantLayout
            shared={props}
            title="Borradores de /aprender · Asistente TI"
            tab="drafts"
            counts={manage ? { drafts: drafts_count, unanswered: unanswered_count } : undefined}
            phoneHeader={phoneHeader}
        >
            {content}
        </AssistantLayout>
    );
}
