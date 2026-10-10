import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/Components/ui/Button';
import { Field, useFieldIds } from '@/Components/ui/Field';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import { repoNameError } from '@/lib/brief';
import type { BriefCopyControl } from './BriefPanel';

export interface RepoCreateFooterProps {
    /** "[ORG]", fixed prefix of the name. */
    organization: string;
    defaultName: string;
    /** The CLAUDE.md shown in the panel; sent as `claude_md`. */
    claudeMd: string;
    /** `route('ai.projects.repository.create', { project })`. */
    action: string;
    copy: BriefCopyControl;
}

/**
 * Footer of the "CLAUDE.md inicial" tab (D-BriefProject): repository name
 * with the organization as a fixed prefix, "Copiar" and "Crear repositorio
 * con este CLAUDE.md". Only rendered for `ai.github.manage` while the
 * project has no repository.
 */
export function RepoCreateFooter({ organization, defaultName, claudeMd, action, copy }: RepoCreateFooterProps) {
    const form = useForm<{ name: string; claude_md: string }>({ name: defaultName, claude_md: claudeMd });
    const hint = (
        <span className="flex items-start gap-1.5">
            <Icon name="lock" size={13} className="mt-0.5 shrink-0" />
            Solo con el permiso ai.github.manage · se crea privado, con README.md, CLAUDE.md y docs/, y queda enlazado al proyecto
        </span>
    );
    const error = form.errors.name ?? form.errors.claude_md;
    const ids = useFieldIds(undefined, hint, error);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const invalid = repoNameError(form.data.name);
        if (invalid) {
            form.setError('name', invalid);
            return;
        }
        form.transform((data) => ({ name: data.name.trim(), claude_md: claudeMd }));
        form.post(action, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} noValidate className="flex w-full flex-col gap-2.5">
            <Field label={
                    <>
                        Nombre del repositorio<span className="sr-only"> en {organization}</span>
                    </>
                } hint={hint} error={error} ids={ids}>
                <div
                    className={cx(
                        'flex h-11 w-full items-stretch overflow-hidden rounded-control border bg-surface focus-within:outline-2 focus-within:outline-offset-1',
                        error ? 'border-status-failure focus-within:outline-status-failure' : 'border-line-strong',
                    )}
                >
                    <span aria-hidden="true" className="flex shrink-0 items-center border-r border-line-strong bg-subtle px-3 font-mono text-sm text-fg-soft">
                        {organization}/
                    </span>
                    <input
                        id={ids.inputId}
                        value={form.data.name}
                        autoComplete="off"
                        spellCheck={false}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={ids.describedBy}
                        onChange={(event) => {
                            form.setData('name', event.target.value);
                            form.clearErrors('name');
                        }}
                        className="min-w-0 flex-1 bg-transparent px-3 font-mono text-sm text-fg outline-none"
                    />
                </div>
            </Field>
            <div className="flex gap-2">
                <Button
                    type="button"
                    variant={copy.copied ? 'dark' : 'secondary'}
                    size="lg"
                    icon={copy.copied ? 'check' : 'copy'}
                    aria-label={copy.copied ? 'Copiado' : 'Copiar CLAUDE.md'}
                    onClick={copy.onCopy}
                    className="w-12 px-0 sm:w-auto sm:px-4"
                >
                    <span className="hidden sm:inline">{copy.copied ? 'Copiado' : 'Copiar'}</span>
                </Button>
                <Button type="submit" size="lg" icon="branch" loading={form.processing} className="min-w-0 flex-1">
                    <span className="truncate">
                        Crear repositorio<span className="hidden sm:inline"> con este CLAUDE.md</span>
                    </span>
                </Button>
            </div>
        </form>
    );
}
