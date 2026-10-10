import { Button } from '@/Components/ui/Button';
import type { EffortSize } from '@/lib/priority';
import type { Suggestion } from '@/types/suggestions';
import { GHOST_BUTTON, SuggestionBlock } from './SuggestionBlock';

export interface SizeSuggestionProps {
    suggestion: Suggestion<'size'>;
    now?: string;
    /** Copies the size into Jhon's own "Talla" field. */
    onUse: (size: EffortSize) => void;
    onDiscard?: () => void;
    onRegenerate?: () => void;
    onRetry?: () => void;
    onCancel?: () => void;
    className?: string;
}

/** Suggested talla with its one-line reason, under the size picker. */
export function SizeSuggestion({ suggestion, now, onUse, onDiscard, onRegenerate, onRetry, onCancel, className }: SizeSuggestionProps) {
    const payload = suggestion.payload;
    return (
        <SuggestionBlock
            state={suggestion.state}
            provider={suggestion.provider}
            createdAt={suggestion.created_at}
            now={now}
            label="Sugerencia de IA: talla"
            subtitle=""
            title={payload ? `Talla ${payload.size}` : undefined}
            error={suggestion.error}
            stale={suggestion.stale}
            accepted={suggestion.accepted}
            discarded={suggestion.discarded}
            discardedSummary={payload ? `talla ${payload.size}` : undefined}
            onUse={payload ? () => onUse(payload.size) : undefined}
            onRegenerate={onRegenerate}
            onRetry={onRetry}
            onCancel={onCancel}
            footnote={suggestion.state === 'ready' ? 'Para otra talla, elígela arriba. La talla final siempre es tuya.' : undefined}
            actions={
                suggestion.state === 'ready' && payload ? (
                    <div className="flex flex-wrap items-center gap-1.5">
                        <Button variant="dark" onClick={() => onUse(payload.size)}>
                            Usar {payload.size}
                        </Button>
                        {onDiscard ? (
                            <button type="button" onClick={onDiscard} className={GHOST_BUTTON}>
                                Descartar
                            </button>
                        ) : null}
                    </div>
                ) : undefined
            }
            className={className}
        >
            {payload ? <p className="text-[13px] text-fg-soft">{payload.reason}</p> : null}
        </SuggestionBlock>
    );
}
