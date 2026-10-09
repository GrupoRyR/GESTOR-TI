import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { DocTree, ownerHref, ownerLabel, VISIBILITY_LABELS } from '@/Components/ai/docs/DocParts';
import { autosaveText, templateName, type AutosaveState } from '@/Components/ai/docs/docs';
import { Avatar } from '@/Components/ui/Avatar';
import { Button } from '@/Components/ui/Button';
import { Dialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { MarkdownEditor } from '@/Components/ui/MarkdownEditor';
import { TextArea } from '@/Components/ui/TextArea';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import { useClock } from '@/lib/useClock';
import type { DocsEditProps } from '@/types/pages/ai-knowledge';
import type { PageProps } from '@/types/shared';

/** Wait after the last keystroke before saving. */
const AUTOSAVE_MS = 1500;

function PublishDialog({ open, onClose, docId, version }: { open: boolean; onClose: () => void; docId: number; version: number }) {
    const form = useForm({ summary: '' });
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(route('ai.docs.publish', { doc: docId }), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };
    return (
        <Dialog
            open={open}
            onClose={onClose}
            onSubmit={submit}
            title={`Publicar la versión ${version + 1}`}
            description="Queda en el historial y se puede restaurar. Quien lee el documento ve esta versión."
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" variant="dark" loading={form.processing}>
                        Publicar versión
                    </Button>
                </>
            }
        >
            <TextArea
                label="Qué cambió"
                hint="Una línea: aparece en el historial de versiones."
                value={form.data.summary}
                onChange={(event) => form.setData('summary', event.target.value)}
                error={form.errors.summary}
                rows={3}
                required
            />
        </Dialog>
    );
}

/** B-Docs: tree, Markdown editor with live preview, autosave and document data. */
export default function DocsEdit(props: PageProps<DocsEditProps>) {
    const { doc, tree, saved_at, version, company_id, now, auth, companies } = props;
    const canEdit = can(auth.user, 'ai.docs.edit');
    const clock = useClock({ startAt: now, intervalMs: 30_000 });
    const form = useForm({ markdown: doc.markdown });
    const [state, setState] = useState<AutosaveState>('saved');
    const [publishing, setPublishing] = useState(false);
    const [copied, setCopied] = useState(false);
    const timer = useRef<number | null>(null);
    const company = companies.find((item) => item.id === company_id);

    useEffect(() => () => {
        if (timer.current !== null) window.clearTimeout(timer.current);
    }, []);

    const save = () => {
        setState('saving');
        form.put(route('ai.docs.update', { doc: doc.id }), {
            only: ['saved_at', 'version'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setState('saved'),
            onError: () => setState('error'),
        });
    };

    const onChange = (markdown: string) => {
        form.setData('markdown', markdown);
        setState('dirty');
        if (timer.current !== null) window.clearTimeout(timer.current);
        timer.current = window.setTimeout(save, AUTOSAVE_MS);
    };

    const share = async () => {
        try {
            await navigator.clipboard.writeText(window.location.href);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2500);
        } catch {
            setCopied(false);
        }
    };

    const status = autosaveText({ state, savedAt: saved_at, version, now: clock });
    const visibility = doc.visibility === 'company' ? VISIBILITY_LABELS.company(company?.short_name ?? 'la empresa') : VISIBILITY_LABELS[doc.visibility]();

    return (
        <GestorLayout
            shared={props}
            title={`${doc.title} · Documentación`}
            tab="docs"
            mode="it"
            breadcrumb={[{ label: 'Documentación', href: route('ai.docs.index') }, { label: doc.title }]}
        >
            <div className="grid gap-5 lg:grid-cols-[250px_minmax(0,1fr)] xl:grid-cols-[250px_minmax(0,1fr)_236px]">
                <details className="group rounded-card border border-line bg-surface p-3 lg:hidden">
                    <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 px-1 text-sm font-bold">
                        Todos los documentos
                        <Icon name="chevron-down" size={16} className="transition-transform group-open:rotate-180" />
                    </summary>
                    <DocTree docs={tree} currentId={doc.id} canCreate={canEdit} className="pt-2" />
                </details>
                <DocTree docs={tree} currentId={doc.id} canCreate={canEdit} className="hidden lg:flex" />

                <section aria-labelledby="doc-title" className="flex min-w-0 flex-col gap-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="flex min-w-0 flex-col gap-1">
                            <h1 id="doc-title" className="font-display text-[22px] leading-tight font-bold tracking-[-0.01em] md:text-[26px]">
                                {doc.title} · {doc.owner.name}
                            </h1>
                            <span
                                role="status"
                                aria-live="polite"
                                className={cx(
                                    'flex items-center gap-1.5 text-[13px] font-semibold',
                                    state === 'error' ? 'text-status-failure' : state === 'saved' ? 'text-status-active' : 'text-fg-muted',
                                )}
                            >
                                <Icon name={state === 'error' ? 'alert-triangle' : state === 'saved' ? 'check-circle' : 'clock'} size={14} />
                                {canEdit ? status : `Versión ${version} · solo lectura`}
                            </span>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button variant="secondary" icon={copied ? 'check' : 'link'} onClick={share}>
                                {copied ? 'Enlace copiado' : 'Compartir enlace'}
                            </Button>
                            {canEdit ? (
                                <Button variant="dark" onClick={() => setPublishing(true)} disabled={state === 'saving' || state === 'dirty'}>
                                    Publicar versión
                                </Button>
                            ) : null}
                        </div>
                    </div>
                    <MarkdownEditor
                        label={`Texto de ${doc.title} en Markdown`}
                        value={form.data.markdown}
                        onChange={onChange}
                        readOnly={!canEdit}
                        defaultMode={canEdit ? 'split' : 'preview'}
                        minHeight={520}
                    />
                    {!canEdit ? (
                        <p className="flex items-center gap-2 text-[13px] text-fg-muted">
                            <Icon name="lock" size={14} />
                            Puedes leerlo; para editar necesitas el permiso de documentación.
                        </p>
                    ) : null}
                </section>

                <aside aria-labelledby="doc-data" className="flex min-w-0 flex-col gap-4 lg:col-start-2 xl:col-start-auto">
                    <div className="flex flex-col gap-4 rounded-card border border-line bg-surface p-5">
                        <h2 id="doc-data" className="font-display text-base font-bold">
                            Datos del documento
                        </h2>
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm xl:grid-cols-1">
                            <div className="flex flex-col gap-0.5">
                                <dt className="text-xs font-semibold text-fg-muted">Plantilla</dt>
                                <dd>{templateName(doc.template)}</dd>
                            </div>
                            <div className="flex flex-col gap-0.5">
                                <dt className="text-xs font-semibold text-fg-muted">Pertenece a</dt>
                                <dd>
                                    <Link href={ownerHref(doc.owner)} className="font-semibold text-fg">
                                        {ownerLabel(doc.owner)}
                                    </Link>
                                </dd>
                            </div>
                            <div className="flex flex-col gap-0.5">
                                <dt className="text-xs font-semibold text-fg-muted">Última edición</dt>
                                <dd className="first-letter:uppercase">{formatRelative(doc.updated_at, clock)}</dd>
                            </div>
                            <div className="flex flex-col gap-0.5">
                                <dt className="text-xs font-semibold text-fg-muted">Quién</dt>
                                <dd className="flex items-center gap-2">
                                    <Avatar name={doc.updated_by.name} size="xs" tone="strong" />
                                    {doc.updated_by.name}
                                </dd>
                            </div>
                            <div className="flex flex-col gap-0.5">
                                <dt className="text-xs font-semibold text-fg-muted">Quién lo puede ver</dt>
                                <dd>{visibility}</dd>
                            </div>
                            {doc.repository_path ? (
                                <div className="flex flex-col gap-0.5">
                                    <dt className="text-xs font-semibold text-fg-muted">En el repositorio</dt>
                                    <dd className="font-mono text-[13px] break-all">{doc.repository_path}</dd>
                                </div>
                            ) : null}
                        </dl>
                        <Button variant="secondary" icon="clock" href={route('ai.docs.history', { doc: doc.id })} fullWidth>
                            Ver historial
                        </Button>
                    </div>
                    <p className="rounded-control bg-subtle p-3 text-[13px] leading-snug text-fg-soft">
                        Se guarda solo mientras escribes. Cada vez que publicas queda una versión que puedes restaurar.
                    </p>
                </aside>
            </div>

            {canEdit ? <PublishDialog open={publishing} onClose={() => setPublishing(false)} docId={doc.id} version={version} /> : null}
        </GestorLayout>
    );
}
