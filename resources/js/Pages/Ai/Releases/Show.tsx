import { Link, router, useForm } from '@inertiajs/react';
import { useRef, useState, type ReactNode } from 'react';
import { ExternalLink } from '@/Components/github/ExternalLink';
import { AiOffNote } from '@/Components/suggestions/AiOffNote';
import { SuggestionBlock } from '@/Components/suggestions/SuggestionBlock';
import { Button } from '@/Components/ui/Button';
import { Chip } from '@/Components/ui/Chip';
import { CompanyMark } from '@/Components/ui/CompanyMark';
import { ConfirmDialog } from '@/Components/ui/Dialog';
import { Icon, type IconName } from '@/Components/ui/Icon';
import { MarkdownPreview } from '@/Components/ui/MarkdownPreview';
import { NoPermission } from '@/Components/ui/NoPermission';
import { StatusChip } from '@/Components/ui/StatusChip';
import { TextArea } from '@/Components/ui/TextArea';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatLongDate, formatShortDate, formatTime } from '@/lib/format';
import { noticeRecipients, releaseNoticeMessage } from '@/lib/releaseNotice';
import { route } from '@/lib/route';
import type { Release } from '@/types/github';
import type { ReleaseNotesPayload, ReleasesShowProps } from '@/types/pages/ai-github';
import type { PageProps } from '@/types/shared';

/** "vie 2 oct, 3:58 p. m.". */
const when = (iso: string) => `${formatLongDate(iso).slice(0, 3)} ${formatShortDate(iso)}, ${formatTime(iso)}`;
const sentence = (text: string) => `${text.charAt(0).toUpperCase()}${text.slice(1)}`;

function Card({ title, aside, description, children, className }: { title: ReactNode; aside?: ReactNode; description?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cx('flex min-w-0 flex-col gap-3.5 rounded-card border border-line bg-surface p-4 md:p-5', className)}>
            <div className="flex flex-col gap-1">
                <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <h2 className="flex items-center gap-2 font-display text-lg leading-tight font-bold">{title}</h2>
                    {aside}
                </div>
                {description ? <p className="text-[13px] leading-snug text-fg-muted">{description}</p> : null}
            </div>
            {children}
        </section>
    );
}

function GestorDid({ release }: { release: Release }) {
    const items: { icon: IconName; done: boolean; text: string }[] = [
        ...(release.version_created ? [{ icon: 'check-circle' as IconName, done: true, text: `Creó la versión ${release.tag} en la ficha del producto` }] : []),
        ...release.included.map((request) => ({
            icon: 'check-circle' as IconName,
            done: true,
            text: `Encontró ${request.key}${request.found_in_pr ? ` en el PR #${request.found_in_pr}` : ''} y la pasó a En producción`,
        })),
        release.notified_at
            ? {
                  icon: 'check-circle' as IconName,
                  done: true,
                  text: `Avisó a ${release.notified_count} ${release.notified_count === 1 ? 'solicitante' : 'solicitantes'}`,
              }
            : {
                  icon: 'hourglass' as IconName,
                  done: false,
                  text: `Espera tus notas para avisar ${release.included.length === 1 ? 'al solicitante' : 'a los solicitantes'}`,
              },
    ];
    return (
        <Card title="Lo que hizo el Gestor con este release">
            <ul className="flex flex-col gap-2.5">
                {items.map((item) => (
                    <li key={item.text} className="flex items-start gap-2.5 text-sm leading-snug">
                        <Icon name={item.icon} size={16} className={cx('mt-0.5 shrink-0', item.done ? 'text-status-active' : 'text-status-maintenance')} />
                        <span className={item.done ? undefined : 'font-semibold'}>{item.text}</span>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

/** D-ReleaseNotes: a GitHub release became a product version; Jhon writes the user notes and notifies each requester. */
export default function ReleasesShow(props: PageProps<ReleasesShowProps>) {
    const { product, release, ai, ai_usage, companies, auth, errors = {} } = props;
    const company = companies.find((item) => item.id === product.company_id);
    const allowed = can(auth.user, 'ai.manage');
    const params = { product: product.slug, release: release.tag };
    const form = useForm<ReleaseNotesPayload>({ user_notes: release.user_notes ?? '', suggestion_id: null });
    const [suggestion, setSuggestion] = useState(props.suggestion);
    const [confirming, setConfirming] = useState(false);
    const notesRef = useRef<HTMLTextAreaElement>(null);
    const recipients = noticeRecipients(release.included);
    const sent = release.notified_at !== null;
    const notifyLabel = `Avisar a ${recipients.length} ${recipients.length === 1 ? 'solicitante' : 'solicitantes'}`;
    const notesError = form.errors.user_notes ?? errors.user_notes;

    const use = (focus: boolean) => {
        if (!suggestion?.payload) return;
        form.setData({ user_notes: suggestion.payload.body, suggestion_id: suggestion.id });
        form.clearErrors('user_notes');
        setSuggestion({ ...suggestion, state: 'accepted', accepted: { by: auth.user.name, at: props.now } });
        if (focus) window.requestAnimationFrame(() => notesRef.current?.focus());
    };

    const discard = () => {
        if (!suggestion) return;
        setSuggestion({ ...suggestion, state: 'discarded', discarded: { by: auth.user.name, at: props.now, reason: null } });
        router.post(route('ai.suggestions.discard', { suggestion: suggestion.id }), {}, { preserveScroll: true, preserveState: true });
    };

    const regenerate = () => {
        if (!suggestion) return;
        setSuggestion({ ...suggestion, state: 'generating', payload: null });
        router.post(route('ai.suggestions.regenerate', { suggestion: suggestion.id }), {}, { preserveScroll: true, only: ['suggestion'] });
    };

    const save = () => form.put(route('ai.releases.notes.update', params), { preserveScroll: true });

    const askNotify = () => {
        if (!form.data.user_notes.trim()) {
            form.setError('user_notes', 'Escribe las notas para los usuarios antes de avisar.');
            notesRef.current?.focus();
            return;
        }
        setConfirming(true);
    };

    const notify = () => form.post(route('ai.releases.notify', params), { preserveScroll: true, onFinish: () => setConfirming(false) });

    return (
        <GestorLayout
            shared={props}
            title={`${product.name} ${release.tag} · Productos`}
            tab="products"
            mode="it"
            breadcrumb={[
                { label: 'Productos', href: route('ai.products.index') },
                { label: product.name, href: route('ai.products.show', { product: product.slug }) },
                { label: `Versión ${release.tag}` },
            ]}
        >
            {!allowed ? (
                <NoPermission description="Las notas de versión las prepara el equipo de TI. Si necesitas entrar, pídele el permiso a un administrador del Gestor TI." />
            ) : (
                <div className="flex flex-col gap-4">
                    <section aria-labelledby="release-title" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5 md:flex-row md:items-start md:p-6">
                        <div className="flex min-w-0 flex-1 items-start gap-3.5">
                            {company ? <CompanyMark company={company} size={44} /> : null}
                            <div className="flex min-w-0 flex-col gap-1.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 id="release-title" className="font-display text-[24px] leading-tight font-bold tracking-[-0.02em] md:text-[28px]">
                                        {product.name} <span className="tabular">{release.tag}</span>
                                    </h1>
                                    {release.version_created ? (
                                        <Chip tone="green" icon="check-circle" size="sm">
                                            Versión creada automáticamente
                                        </Chip>
                                    ) : null}
                                </div>
                                <p className="text-sm leading-snug text-fg-soft">
                                    Release publicado en GitHub el {when(release.published_at)} por {release.author.name} · {release.repo} · etiqueta{' '}
                                    <code className="rounded-[6px] bg-subtle px-1.5 py-0.5 font-mono text-xs text-fg">{release.tag}</code>
                                </p>
                            </div>
                        </div>
                        <ExternalLink href={release.url} variant="button" srHint="se abre en otra pestaña" className="self-start">
                            Ver en GitHub
                        </ExternalLink>
                    </section>

                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] lg:items-start">
                        <div className="flex min-w-0 flex-col gap-4">
                            <Card title="Notas para los usuarios" description="En palabras sencillas. Las ve quien pidió el cambio.">
                                {ai === 'ready' && suggestion ? (
                                    <SuggestionBlock
                                        state={suggestion.state}
                                        provider={suggestion.provider}
                                        createdAt={suggestion.created_at}
                                        now={props.now}
                                        label="Sugerencia de IA: notas para los usuarios"
                                        title={suggestion.payload?.title}
                                        footnote={suggestion.state === 'ready' ? suggestion.payload?.source_note : undefined}
                                        onUse={() => use(false)}
                                        onEdit={() => use(true)}
                                        onDiscard={discard}
                                        onRegenerate={regenerate}
                                        accepted={suggestion.accepted}
                                        discarded={suggestion.discarded}
                                        discardedSummary="notas de la versión"
                                        error={suggestion.error}
                                        onRetry={regenerate}
                                        stale={suggestion.stale}
                                    >
                                        {suggestion.payload
                                            ? suggestion.payload.body.split(/\n{2,}/).map((paragraph) => <p key={paragraph}>{paragraph}</p>)
                                            : null}
                                    </SuggestionBlock>
                                ) : null}
                                {ai !== 'ready' ? <AiOffNote availability={ai} usage={ai_usage} integrationsHref={route('admin.integrations.index')} /> : null}

                                <TextArea
                                    ref={notesRef}
                                    label="Texto final para los usuarios"
                                    rows={6}
                                    value={form.data.user_notes}
                                    error={notesError}
                                    placeholder="Qué cambió para quien usa la app, sin palabras técnicas."
                                    onChange={(event) => {
                                        form.setData('user_notes', event.target.value);
                                        form.clearErrors('user_notes');
                                    }}
                                />
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <p className="max-w-[60ch] text-xs leading-normal text-fg-muted">
                                        {recipients.length === 1 ? `${recipients[0]!.requester.name.split(' ')[0]} nunca ve` : 'Los solicitantes nunca ven'} la sugerencia, solo el
                                        texto final. Sin llave de IA o con el tope del mes alcanzado, el bloque de sugerencia no aparece y todo lo demás funciona igual.
                                    </p>
                                    <Button variant="secondary" onClick={save} disabled={!form.isDirty} loading={form.processing && !confirming}>
                                        Guardar notas
                                    </Button>
                                </div>
                            </Card>

                            <Card
                                title="Notas técnicas de GitHub"
                                aside={
                                    <span className="inline-flex items-center gap-1.5 text-[13px] text-fg-muted">
                                        <Icon name="lock" size={13} />
                                        Solo lectura · tal como vienen del release
                                    </span>
                                }
                            >
                                <div className="rounded-control bg-ground px-4 py-3">
                                    <MarkdownPreview source={release.technical_notes} emptyText="El release no trae notas." className="text-sm" />
                                </div>
                                {release.compare ? (
                                    <span className="flex flex-wrap items-center gap-x-1.5 text-[13px] text-fg-soft">
                                        Comparar:
                                        <ExternalLink href={release.compare.url} className="font-mono text-[13px]">
                                            {release.compare.label}
                                        </ExternalLink>
                                    </span>
                                ) : null}
                            </Card>
                        </div>

                        <div className="flex min-w-0 flex-col gap-4">
                            <GestorDid release={release} />

                            <Card
                                title={
                                    <>
                                        Solicitudes incluidas
                                        <span className="tabular rounded-chip bg-subtle px-2 py-0.5 font-sans text-xs font-bold text-fg-soft">{release.included.length}</span>
                                    </>
                                }
                            >
                                <ul className="flex flex-col gap-2">
                                    {release.included.map((request) => (
                                        <li key={request.key}>
                                            <Link
                                                href={route('ai.requests.show', { request: request.key })}
                                                className="flex flex-col gap-1.5 rounded-control border border-line px-3.5 py-3 text-fg no-underline hover:bg-ground"
                                            >
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <b className="tabular text-[13px]">{request.key}</b>
                                                    <StatusChip status={request.status} size="sm" />
                                                </span>
                                                <span className="text-sm font-semibold">{request.title}</span>
                                                <span className="text-[13px] text-fg-muted">
                                                    Pidió {request.requester.name} · {request.requester.company} · {request.requester.area}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </Card>

                            <Card
                                title={sent ? 'Aviso enviado' : notifyLabel}
                                description={`Para ${recipients.map((recipient) => recipient.requester.name).join(', ')} · por Telegram y la campana`}
                            >
                                {recipients.map((recipient) => (
                                    <blockquote
                                        key={recipient.requester.name}
                                        aria-label={`Mensaje para ${recipient.requester.name}`}
                                        className="flex flex-col gap-2 rounded-control bg-ground px-4 py-3 text-sm leading-normal"
                                    >
                                        {releaseNoticeMessage({
                                            requesterName: recipient.requester.name,
                                            requests: recipient.requests,
                                            productName: product.name,
                                            tag: release.tag,
                                            notes: form.data.user_notes,
                                        }).map((paragraph, index) => (
                                            <p key={index} className={cx('whitespace-pre-line', index === 1 && !form.data.user_notes.trim() && 'text-fg-muted italic')}>
                                                {paragraph}
                                            </p>
                                        ))}
                                    </blockquote>
                                ))}
                                {sent && release.notified_at ? (
                                    <p role="status" className="flex items-start gap-2.5 rounded-control border border-line px-3.5 py-3 text-[13px] leading-snug">
                                        <Icon name="check-circle" size={18} className="shrink-0 text-status-active" />
                                        <span>
                                            <b>Aviso enviado a {recipients.map((recipient) => recipient.requester.name).join(', ')}</b>
                                            <span className="block text-fg-muted">{sentence(when(release.notified_at))} · Telegram y campana · calificación pendiente</span>
                                        </span>
                                    </p>
                                ) : (
                                    <Button variant="dark" icon="send" onClick={askNotify} aria-haspopup="dialog" className="self-start">
                                        {notifyLabel}
                                    </Button>
                                )}
                            </Card>
                        </div>
                    </div>

                    <ConfirmDialog
                        open={confirming}
                        onClose={() => setConfirming(false)}
                        onConfirm={notify}
                        processing={form.processing}
                        tone="primary"
                        title={`¿${notifyLabel}?`}
                        description="Se guardan estas notas y cada solicitante recibe el mensaje por Telegram y la campana. El aviso no se puede deshacer."
                        confirmLabel="Avisar ahora"
                    />
                </div>
            )}
        </GestorLayout>
    );
}
