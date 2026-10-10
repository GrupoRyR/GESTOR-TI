import { Button } from '@/Components/ui/Button';
import { StatusChip } from '@/Components/ui/StatusChip';
import type { Suggestion } from '@/types/suggestions';
import { GHOST_BUTTON, SuggestionBlock } from './SuggestionBlock';

export interface DuplicateSuggestionCardProps {
    suggestion: Suggestion<'duplicate'>;
    now?: string;
    /** Side-by-side page (`Ai/Requests/Compare`). */
    compareHref?: string;
    onCompare?: () => void;
    /** Opens the usual merge dialog; nothing merges by itself. */
    onMerge: () => void;
    /** Hides the suggestion for this request (asks an optional reason). */
    onNotDuplicate: () => void;
    className?: string;
}

/**
 * AI duplicate suggestion: neutral block (the rule-based duplicate warning
 * stays the amber banner of the request page).
 */
export function DuplicateSuggestionCard({ suggestion, now, compareHref, onCompare, onMerge, onNotDuplicate, className }: DuplicateSuggestionCardProps) {
    const payload = suggestion.payload;
    const target = payload?.request;

    return (
        <SuggestionBlock
            state={suggestion.state}
            provider={suggestion.provider}
            createdAt={suggestion.created_at}
            now={now}
            label="Sugerencia de IA: posible duplicado"
            title={
                target ? (
                    <>
                        Posible duplicado: {target.key} <span className="font-semibold text-fg-soft">· {target.title}</span>
                    </>
                ) : undefined
            }
            error={suggestion.error}
            stale={suggestion.stale}
            accepted={suggestion.accepted}
            discarded={suggestion.discarded}
            discardedSummary={target ? `posible duplicado de ${target.key}` : undefined}
            actions={
                suggestion.state === 'ready' || suggestion.state === 'stale' ? (
                    <div className="flex flex-wrap items-center gap-1.5">
                        {compareHref ? (
                            <Button variant="secondary" href={compareHref}>
                                Comparar
                            </Button>
                        ) : onCompare ? (
                            <Button variant="secondary" onClick={onCompare}>
                                Comparar
                            </Button>
                        ) : null}
                        <Button variant="dark" icon="merge" onClick={onMerge}>
                            Unir
                        </Button>
                        <button type="button" onClick={onNotDuplicate} className={GHOST_BUTTON}>
                            No es duplicado
                        </button>
                    </div>
                ) : undefined
            }
            className={className}
        >
            {payload && target ? (
                <>
                    <span className="flex flex-wrap items-center gap-2 text-[13px] text-fg-soft">
                        <StatusChip status={target.status} size="sm" />
                        <span className="tabular">
                            {target.people} {target.people === 1 ? 'persona' : 'personas'}
                        </span>
                        <span>· Parecido {payload.level}</span>
                    </span>
                    <span className="text-[13px] text-fg-soft">Por qué: {payload.reason_summary}.</span>
                </>
            ) : null}
        </SuggestionBlock>
    );
}
