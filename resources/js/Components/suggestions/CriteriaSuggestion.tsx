import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import type { Suggestion } from '@/types/suggestions';
import { GHOST_BUTTON, SuggestionBlock } from './SuggestionBlock';

type CriteriaItem = NonNullable<Suggestion<'criteria'>['payload']>['items'][number];

export interface CriteriaSuggestionProps {
    suggestion: Suggestion<'criteria'>;
    now?: string;
    /** Items already copied into the request criteria. */
    usedIds?: number[];
    onUseItem: (item: CriteriaItem) => void;
    onUseAll: (items: CriteriaItem[]) => void;
    onDiscard?: () => void;
    onRegenerate?: () => void;
    onRetry?: () => void;
    onCancel?: () => void;
    className?: string;
}

/** Proposed acceptance criteria; each one can be used alone. Used ones become Jhon's own. */
export function CriteriaSuggestion({ suggestion, now, usedIds = [], onUseItem, onUseAll, onDiscard, onRegenerate, onRetry, onCancel, className }: CriteriaSuggestionProps) {
    const items = suggestion.payload?.items ?? [];
    const pending = items.filter((item) => !usedIds.includes(item.id));
    const count = items.length;

    return (
        <SuggestionBlock
            state={suggestion.state}
            provider={suggestion.provider}
            createdAt={suggestion.created_at}
            now={now}
            label="Sugerencia de IA: criterios de aceptación"
            subtitle={`${count} ${count === 1 ? 'criterio propuesto, revísalo antes de usarlo' : 'criterios propuestos, revísalos antes de usarlos'}`}
            error={suggestion.error}
            stale={suggestion.stale}
            accepted={suggestion.accepted}
            discarded={suggestion.discarded}
            discardedSummary={`${count} criterios`}
            onRegenerate={onRegenerate}
            onRetry={onRetry}
            onCancel={onCancel}
            onUse={pending.length > 0 ? () => onUseAll(pending) : undefined}
            actions={
                suggestion.state === 'ready' ? (
                    <div className="flex flex-wrap items-center gap-1.5">
                        {pending.length > 0 ? (
                            <Button variant="dark" icon="check" onClick={() => onUseAll(pending)}>
                                {pending.length === count ? `Usar los ${count}` : `Usar los ${pending.length} que faltan`}
                            </Button>
                        ) : null}
                        {onDiscard ? (
                            <button type="button" onClick={onDiscard} className={GHOST_BUTTON}>
                                Descartar
                            </button>
                        ) : null}
                        {onRegenerate ? (
                            <button type="button" onClick={onRegenerate} className={GHOST_BUTTON}>
                                <Icon name="refresh" size={14} />
                                Volver a sugerir
                            </button>
                        ) : null}
                    </div>
                ) : undefined
            }
            className={className}
        >
            {items.length > 0 ? (
                <ol className="flex flex-col gap-1.5">
                    {items.map((item, index) => {
                        const used = usedIds.includes(item.id);
                        return (
                            <li key={item.id} className="flex items-center gap-2.5 rounded-control border border-line bg-surface py-1 pr-1 pl-2.5">
                                <span aria-hidden="true" className="tabular flex size-6 shrink-0 items-center justify-center rounded-full bg-subtle text-xs font-bold text-fg-soft">
                                    {index + 1}
                                </span>
                                <span className="min-w-0 flex-1 text-[13px] leading-snug">{item.text}</span>
                                {used ? (
                                    <span className="inline-flex h-11 shrink-0 items-center gap-1 px-2.5 text-[13px] font-semibold text-fg-muted">
                                        <Icon name="check" size={14} />
                                        Usado
                                    </span>
                                ) : suggestion.state === 'ready' || suggestion.state === 'stale' ? (
                                    <button
                                        type="button"
                                        onClick={() => onUseItem(item)}
                                        aria-label={`Usar el criterio ${index + 1}`}
                                        className={GHOST_BUTTON}
                                    >
                                        Usar
                                    </button>
                                ) : null}
                            </li>
                        );
                    })}
                </ol>
            ) : null}
        </SuggestionBlock>
    );
}
