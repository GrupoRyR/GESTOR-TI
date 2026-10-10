import { CompanyMark } from '@/Components/ui/CompanyMark';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import type { PersonChoiceMessage, PersonOption } from '@/types/assistant';
import type { Company } from '@/types/shared';
import { AssistantBubble, AssistantText } from './ChatBubbles';

export interface PersonPickerProps {
    message: PersonChoiceMessage;
    /** `shared.companies`, for each option's company mark. */
    companies: Company[];
    onPick: (option: PersonOption) => void;
    /** "Ninguno de los dos". */
    onNone?: () => void;
    /** While the pick is being sent. */
    disabled?: boolean;
}

/** Two people share the name: one button per person (cargo · área · empresa), then "Ninguno de los dos". */
export function PersonPicker({ message, companies, onPick, onNone, disabled = false }: PersonPickerProps) {
    const resolved = message.resolved_option_id;
    const locked = disabled || resolved !== null;
    const noneLabel = message.options.length === 2 ? 'Ninguno de los dos' : 'Ninguno de ellos';

    return (
        <AssistantBubble label="Respuesta del Asistente TI: elegir persona" at={message.at} wide>
            <AssistantText text={message.text} />
            <div role="group" aria-label="Elige a la persona" className="flex flex-col gap-2">
                {message.options.map((option) => {
                    const company = companies.find((item) => item.id === option.company_id);
                    const chosen = option.id === resolved;
                    return (
                        <button
                            key={option.id}
                            type="button"
                            disabled={locked}
                            aria-pressed={resolved !== null ? chosen : undefined}
                            onClick={() => onPick(option)}
                            className={cx(
                                'flex min-h-16 w-full items-center gap-3 rounded-control border px-3 py-2.5 text-left text-fg transition-colors duration-150',
                                chosen ? 'border-fg bg-subtle' : 'border-line-strong bg-surface',
                                !locked && 'hover:bg-subtle',
                                locked && !chosen && 'opacity-60',
                            )}
                        >
                            {company ? (
                                <CompanyMark company={company} size={32} decorative className="font-display" />
                            ) : (
                                <span aria-hidden="true" className="size-8 shrink-0 rounded-chip bg-subtle" />
                            )}
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <b className="text-[15px]">{option.name}</b>
                                <span className="text-[13px] text-fg-soft">
                                    {option.job_title} · {option.area} · {option.company_name}
                                </span>
                            </span>
                            <Icon name={chosen ? 'check' : 'chevron-right'} size={18} />
                        </button>
                    );
                })}
                {onNone && resolved === null ? (
                    <button
                        type="button"
                        disabled={locked}
                        onClick={onNone}
                        className="min-h-11 w-full rounded-control bg-subtle px-3 text-sm font-bold text-fg-soft hover:text-fg disabled:opacity-60"
                    >
                        {noneLabel}
                    </button>
                ) : null}
            </div>
            <p className="flex items-start gap-2 text-xs leading-[1.4] text-fg-muted">
                <Icon name="shield" size={14} className="mt-px" />
                La contraseña llega aparte, en una tarjeta del Gestor TI. No pasa por la IA.
            </p>
        </AssistantBubble>
    );
}
