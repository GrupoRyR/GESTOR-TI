import { useForm } from '@inertiajs/react';
import { useEffect, useId, useMemo, useState, type FormEvent } from 'react';
import { Button } from '@/Components/ui/Button';
import { Checkbox } from '@/Components/ui/Checkbox';
import { Dialog } from '@/Components/ui/Dialog';
import { Icon } from '@/Components/ui/Icon';
import { Select } from '@/Components/ui/Select';
import { cx } from '@/Components/ui/utils';
import { formatRelative } from '@/lib/format';
import { repoLinkOptions } from '@/lib/repoLink';
import type { RepoLinkPayload, RepoLinkSetup } from '@/types/pages/ai-github';
import type { FormErrors } from '@/types/shared';

export interface RepoLinkDialogProps {
    setup: RepoLinkSetup;
    open: boolean;
    onClose: () => void;
    /** `ai.products.repository.store` or `ai.projects.repository.store` for the target. */
    action: string;
    /** Errors from a failed store (`repo`, `branch`). */
    serverErrors?: FormErrors;
    now?: string;
}

/**
 * D-RepoLink: pick the repository of a product or project among the ones
 * with the app installed. Repos linked elsewhere stay visible but disabled.
 * The Gestor only reads code and creates issues; it never changes files.
 */
export function RepoLinkDialog({ setup, open, onClose, action, serverErrors = {}, now }: RepoLinkDialogProps) {
    const form = useForm<RepoLinkPayload>({ ...setup.defaults });
    const [query, setQuery] = useState('');
    const searchId = useId();
    const legendId = useId();
    const at = now ? new Date(now) : new Date();

    useEffect(() => {
        if (Object.keys(serverErrors).length > 0) form.setError(serverErrors as Partial<Record<keyof RepoLinkPayload, string>>);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const options = useMemo(() => repoLinkOptions(setup.repos, setup.target, query), [setup.repos, setup.target, query]);
    const chosen = setup.repos.find((repo) => repo.full_name === form.data.repo) ?? null;
    const branches = chosen?.branches ?? [form.data.branch];

    const choose = (fullName: string) => {
        const repo = setup.repos.find((item) => item.full_name === fullName);
        form.setData((data) => ({ ...data, repo: fullName, branch: repo?.default_branch ?? data.branch }));
        form.clearErrors('repo');
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!form.data.repo) {
            form.setError('repo', 'Elige el repositorio.');
            return;
        }
        form.post(action, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            size="md"
            title="Vincular repositorio"
            description={`Elige dónde vive el código de ${setup.target.name}. Solo aparecen los repositorios de ${setup.organization} con la app instalada.`}
            onSubmit={submit}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button type="submit" variant="dark" loading={form.processing}>
                        Vincular
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-1.5">
                <label htmlFor={searchId} className="text-[13px] font-semibold">
                    Buscar repositorio
                </label>
                <div className="relative">
                    <Icon name="search" size={16} className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-fg-muted" />
                    <input
                        id={searchId}
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Nombre o lenguaje"
                        autoComplete="off"
                        className="h-11 w-full rounded-control border border-line-strong bg-surface pr-3.5 pl-10 text-base"
                    />
                </div>
            </div>

            <fieldset aria-labelledby={legendId} aria-describedby={form.errors.repo ? `${legendId}-error` : undefined} className="flex flex-col gap-1.5">
                <legend id={legendId} className="mb-1.5 text-[13px] font-semibold">
                    Repositorio
                </legend>
                {options.length === 0 ? (
                    <p className="rounded-control bg-ground px-3.5 py-3 text-[13px] text-fg-muted">Ningún repositorio coincide con “{query.trim()}”.</p>
                ) : (
                    <ul className="flex max-h-[264px] flex-col overflow-y-auto rounded-control border border-line">
                        {options.map(({ repo, disabled, note }, index) => {
                            const checked = form.data.repo === repo.full_name;
                            const meta = [repo.language, repo.pushed_at ? `último push ${formatRelative(repo.pushed_at, at)}` : null].filter(Boolean).join(' · ');
                            return (
                                <li key={repo.full_name} className={cx(index > 0 && 'border-t border-line')}>
                                    <label
                                        className={cx(
                                            'flex min-h-14 items-center gap-3 px-3.5 py-2.5',
                                            disabled ? 'cursor-not-allowed text-fg-muted' : 'cursor-pointer hover:bg-ground',
                                            checked && 'bg-ground',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="repo"
                                            value={repo.full_name}
                                            checked={checked}
                                            disabled={disabled}
                                            onChange={() => choose(repo.full_name)}
                                            className="size-5 shrink-0 accent-fg"
                                        />
                                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <span className={cx('truncate text-sm font-bold', disabled ? 'text-fg-muted' : 'text-fg')}>{repo.full_name}</span>
                                            <span className="text-xs text-fg-muted">{note ? `${note}${meta ? ` · ${meta}` : ''}` : meta}</span>
                                        </span>
                                        {repo.private ? (
                                            <span className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-fg-soft">
                                                <Icon name="lock" size={12} />
                                                Privado
                                            </span>
                                        ) : null}
                                    </label>
                                </li>
                            );
                        })}
                    </ul>
                )}
                {form.errors.repo ? (
                    <p id={`${legendId}-error`} className="text-[13px] font-semibold text-status-failure">
                        {form.errors.repo}
                    </p>
                ) : null}
            </fieldset>

            <Select
                label="Rama principal"
                value={form.data.branch}
                onChange={(event) => form.setData('branch', event.target.value)}
                options={branches.map((branch) => ({ value: branch, label: branch }))}
                hint="De aquí salen CI y último despliegue"
                error={form.errors.branch}
            />

            <div className="flex flex-col">
                <Checkbox
                    label="Leer documentación (README, CLAUDE.md, docs/)"
                    checked={form.data.read_docs}
                    onChange={(event) => form.setData('read_docs', event.target.checked)}
                    containerClassName="py-1.5"
                />
                <Checkbox
                    label="Recibir eventos (PR, CI, releases)"
                    checked={form.data.receive_events}
                    onChange={(event) => form.setData('receive_events', event.target.checked)}
                    containerClassName="py-1.5"
                />
            </div>

            <p className="flex items-start gap-2 rounded-control bg-ground px-3.5 py-3 text-[13px] leading-normal text-fg-soft">
                <Icon name="shield" size={16} className="mt-0.5 shrink-0" />
                <span>
                    El Gestor solo lee el código y crea issues. Nunca cambia archivos ni ramas.
                    {setup.pending_note ? ` ${setup.pending_note}` : ''}
                </span>
            </p>
        </Dialog>
    );
}
