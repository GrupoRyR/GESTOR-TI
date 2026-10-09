import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ownerLabel } from '@/Components/ai/docs/DocParts';
import { DOC_TEMPLATES } from '@/Components/ai/docs/docs';
import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { NoPermission } from '@/Components/ui/NoPermission';
import { Select } from '@/Components/ui/Select';
import { TextField } from '@/Components/ui/TextField';
import { cx } from '@/Components/ui/utils';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import type { DocTemplate } from '@/types/ai';
import type { DocsCreateProps, DocStorePayload } from '@/types/pages/ai-knowledge';
import type { PageProps } from '@/types/shared';

const ownerValue = (kind: string, key: string) => `${kind}:${key}`;

/** B-DocsTemplates: pick a template (with its sections), the project or product and a title. */
export default function DocsCreate(props: PageProps<DocsCreateProps>) {
    const { owners, defaults, auth } = props;
    const first = defaults ?? (owners[0] ? { owner_kind: owners[0].kind, owner_key: owners[0].key } : null);
    const form = useForm<DocStorePayload>({
        template: 'user_manual',
        owner_kind: first?.owner_kind ?? 'product',
        owner_key: first?.owner_key ?? '',
        title: 'Manual de usuario',
    });
    const [titleTouched, setTitleTouched] = useState(false);

    if (!can(auth.user, 'ai.docs.edit')) {
        return (
            <GestorLayout shared={props} title="Nuevo documento · Documentación" tab="docs" mode="it">
                <NoPermission title="Crear documentos necesita el permiso de documentación" description="Pídeselo a Jhon Castro o a Leonardo Herrera." />
            </GestorLayout>
        );
    }

    const choose = (template: DocTemplate) => {
        form.setData((data) => ({
            ...data,
            template,
            title: titleTouched ? data.title : (DOC_TEMPLATES.find((item) => item.key === template)?.name ?? data.title),
        }));
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(route('ai.docs.store'));
    };

    return (
        <GestorLayout shared={props} title="Nuevo documento · Documentación" tab="docs" mode="it">
            <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex max-w-[640px] flex-col gap-1">
                        <Link href={route('ai.docs.index')} className="flex min-h-11 w-fit items-center gap-1 text-[13px] font-semibold text-fg-soft hover:text-fg">
                            <Icon name="chevron-left" size={16} />
                            Documentación
                        </Link>
                        <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] md:text-[30px]">Nuevo documento</h1>
                        <p className="text-sm text-fg-soft">Elige una plantilla. Trae las secciones listas para llenar; puedes quitar las que no apliquen.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" href={route('ai.docs.index')}>
                            Cancelar
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Crear con esta plantilla
                        </Button>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:max-w-[760px]">
                    <Select
                        label="Para"
                        value={ownerValue(form.data.owner_kind, form.data.owner_key)}
                        onChange={(event) => {
                            const owner = owners.find((item) => ownerValue(item.kind, item.key) === event.target.value);
                            if (owner) form.setData((data) => ({ ...data, owner_kind: owner.kind, owner_key: owner.key }));
                        }}
                        options={owners.map((owner) => ({
                            value: ownerValue(owner.kind, owner.key),
                            label: `${ownerLabel(owner)} (${owner.kind === 'project' ? 'proyecto' : 'producto'})`,
                        }))}
                        error={form.errors.owner_key}
                    />
                    <TextField
                        label="Título"
                        value={form.data.title}
                        onChange={(event) => {
                            setTitleTouched(true);
                            form.setData('title', event.target.value);
                        }}
                        error={form.errors.title}
                        required
                    />
                </div>

                <fieldset className="flex flex-col gap-2">
                    <legend className="mb-2 text-sm font-bold">Plantilla</legend>
                    {form.errors.template ? <p className="text-[13px] font-semibold text-status-failure">{form.errors.template}</p> : null}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {DOC_TEMPLATES.map((template) => {
                            const selected = form.data.template === template.key;
                            return (
                                <label
                                    key={template.key}
                                    className={cx(
                                        'relative flex cursor-pointer flex-col items-start gap-2 rounded-card bg-surface p-4 transition-colors duration-150 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-fg',
                                        selected ? 'border-2 border-brand-button p-[15px]' : 'border border-line hover:border-line-strong',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="template"
                                        value={template.key}
                                        checked={selected}
                                        onChange={() => choose(template.key)}
                                        className="sr-only"
                                    />
                                    <span className="flex w-full items-start justify-between gap-2">
                                        <span
                                            aria-hidden="true"
                                            className={cx(
                                                'flex size-10 items-center justify-center rounded-icon',
                                                selected ? 'bg-brand-tint text-brand-ink' : 'bg-subtle text-fg',
                                            )}
                                        >
                                            <Icon name={template.icon} size={20} />
                                        </span>
                                        {selected ? (
                                            <span aria-hidden="true" className="flex size-6 items-center justify-center rounded-full bg-brand-button text-brand-button-ink">
                                                <Icon name="check" size={14} strokeWidth={2.6} />
                                            </span>
                                        ) : null}
                                    </span>
                                    <span className="text-base font-bold">{template.name}</span>
                                    <span className="text-[13px] leading-snug text-fg-soft">{template.description}</span>
                                    <span className="w-full border-t border-line pt-2 text-xs leading-snug text-fg-muted">Secciones: {template.sections.join(' · ')}</span>
                                    {template.warning ? (
                                        <span className="flex w-full items-start gap-1.5 rounded-control bg-status-maintenance-bg p-2 text-xs leading-snug font-semibold text-status-maintenance">
                                            <Icon name="lock" size={13} className="mt-px shrink-0" />
                                            {template.warning}
                                        </span>
                                    ) : null}
                                </label>
                            );
                        })}
                    </div>
                </fieldset>
            </form>
        </GestorLayout>
    );
}
