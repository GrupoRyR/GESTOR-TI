import { useId, useMemo, useState, type KeyboardEvent } from 'react';
import { Avatar } from '@/Components/ui/Avatar';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import type { RecipientOption } from '@/types/pages/ai-projects';

export interface PersonPickerProps {
    label: string;
    options: RecipientOption[];
    value: number | null;
    onChange: (id: number | null) => void;
    /** Company names by id, for the secondary line ("Ventas · Segurex"). */
    companyName?: (id: number) => string | undefined;
    hint?: string;
    error?: string;
    /** Marks one option "Sugerida". */
    suggestedId?: number | null;
}

const normalize = (text: string) =>
    text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();

/** Search-as-you-type person field (combobox): name, job title or area. */
export function PersonPicker({ label, options, value, onChange, companyName, hint = 'Busca por nombre, cargo o área', error, suggestedId }: PersonPickerProps) {
    const id = useId();
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const selected = options.find((option) => option.person.id === value) ?? null;

    const matches = useMemo(() => {
        const q = normalize(query.trim());
        const list = q
            ? options.filter((option) => normalize(`${option.person.name} ${option.person.job_title ?? ''} ${option.area_name}`).includes(q))
            : options;
        return [...list].sort((a, b) => Number(b.person.id === suggestedId) - Number(a.person.id === suggestedId));
    }, [options, query, suggestedId]);

    const detail = (option: RecipientOption) =>
        [option.person.job_title, option.area_name, companyName?.(option.company_id)].filter((part, index, all) => part && all.indexOf(part) === index).join(' · ');

    const choose = (option: RecipientOption) => {
        onChange(option.person.id);
        setQuery('');
        setOpen(false);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setOpen(true);
            setActive((current) => (matches.length === 0 ? 0 : (current + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length));
        } else if (event.key === 'Enter' && open && matches[active]) {
            event.preventDefault();
            choose(matches[active]!);
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    if (selected) {
        return (
            <div className="flex flex-col gap-1.5">
                <span id={`${id}-label`} className="text-[13px] font-semibold">
                    {label}
                </span>
                <div aria-labelledby={`${id}-label`} role="group" className="flex min-h-12 items-center gap-2.5 rounded-control border border-line-strong bg-surface py-1 pr-1 pl-3">
                    <Avatar name={selected.person.name} size="sm" />
                    <span className="flex min-w-0 flex-1 flex-col">
                        <b className="truncate text-sm">{selected.person.name}</b>
                        <span className="truncate text-xs text-fg-muted">{detail(selected)}</span>
                    </span>
                    <button
                        type="button"
                        onClick={() => onChange(null)}
                        aria-label={`Cambiar ${selected.person.name}`}
                        title="Cambiar"
                        className="flex size-11 shrink-0 items-center justify-center rounded-control text-fg-muted hover:bg-subtle hover:text-fg"
                    >
                        <Icon name="x" size={16} />
                    </button>
                </div>
                {hint ? <p className="text-[13px] text-fg-muted">{hint}</p> : null}
            </div>
        );
    }

    return (
        <div className="relative flex flex-col gap-1.5">
            <label htmlFor={id} className="text-[13px] font-semibold">
                {label}
            </label>
            <div className="relative">
                <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-fg-muted">
                    <Icon name="search" size={16} />
                </span>
                <input
                    id={id}
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={`${id}-list`}
                    aria-autocomplete="list"
                    aria-activedescendant={open && matches[active] ? `${id}-option-${matches[active]!.person.id}` : undefined}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={`${id}-hint`}
                    value={query}
                    placeholder="Nombre, cargo o área"
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setOpen(true);
                        setActive(0);
                    }}
                    onFocus={() => setOpen(true)}
                    onBlur={() => window.setTimeout(() => setOpen(false), 120)}
                    onKeyDown={onKeyDown}
                    className={cx(
                        'h-12 w-full rounded-control border bg-surface pr-3 pl-9 text-base placeholder:text-fg-muted',
                        error ? 'border-status-failure' : 'border-line-strong hover:border-fg-muted',
                    )}
                />
            </div>
            {open ? (
                <ul id={`${id}-list`} role="listbox" aria-label={label} className="absolute top-full right-0 left-0 z-30 mt-1 flex max-h-72 flex-col overflow-y-auto rounded-control border border-line bg-surface p-1 shadow-card">
                    {matches.length === 0 ? (
                        <li role="presentation" className="px-3 py-3 text-sm text-fg-muted">
                            Nadie coincide con “{query}”.
                        </li>
                    ) : (
                        matches.map((option, index) => (
                            <li
                                key={option.person.id}
                                id={`${id}-option-${option.person.id}`}
                                role="option"
                                aria-selected={index === active}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => choose(option)}
                                className={cx('flex min-h-12 cursor-pointer items-center gap-2.5 rounded-chip px-2.5', index === active && 'bg-subtle')}
                            >
                                <Avatar name={option.person.name} size="sm" />
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <b className="truncate text-sm">{option.person.name}</b>
                                    <span className="truncate text-xs text-fg-muted">{detail(option)}</span>
                                </span>
                                {option.person.id === suggestedId ? <span className="text-xs font-bold text-status-scheduled">Sugerida</span> : null}
                            </li>
                        ))
                    )}
                </ul>
            ) : null}
            {error ? <p className="text-[13px] font-semibold text-status-failure">{error}</p> : null}
            <p id={`${id}-hint`} className="text-[13px] text-fg-muted">
                {hint}
            </p>
        </div>
    );
}
