import { Button } from '@/Components/ui/Button';
import { Icon } from '@/Components/ui/Icon';
import { cx } from '@/Components/ui/utils';
import type { EffortSize } from '@/lib/priority';
import type { Suggestion } from '@/types/suggestions';
import { GHOST_BUTTON, SUGGESTION_SURFACE, SuggestionBlock } from './SuggestionBlock';

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

export interface SizeSuggestionRowProps {
    suggestion: Suggestion<'size'>;
    onUse: (size: EffortSize) => void;
    onDiscard?: () => void;
    className?: string;
}

/**
 * Phone version of a ready size suggestion: one dashed row under the size
 * picker ("Sugerencia de IA: M." + reason, "Usar M", discard icon).
 */
export function SizeSuggestionRow({ suggestion, onUse, onDiscard, className }: SizeSuggestionRowProps) {
    const payload = suggestion.payload;
    if (!payload) return null;
    return (
        <div
            role="group"
            aria-label="Sugerencia de IA: talla"
            className={cx(SUGGESTION_SURFACE, 'flex items-center gap-2 rounded-control py-1.5 pr-1.5 pl-2.5', className)}
        >
            <span className="min-w-0 flex-1 text-[13px] leading-snug">
                <b>Sugerencia de IA: {payload.size}.</b> <span className="text-fg-soft">{payload.reason}</span>
            </span>
            <Button variant="secondary" onClick={() => onUse(payload.size)} className="shrink-0 px-3">
                Usar {payload.size}
            </Button>
            {onDiscard ? (
                <button
                    type="button"
                    onClick={onDiscard}
                    aria-label="Descartar talla sugerida"
                    className="flex size-11 shrink-0 items-center justify-center rounded-control text-fg-soft hover:bg-subtle hover:text-fg"
                >
                    <Icon name="x" size={16} />
                </button>
            ) : null}
        </div>
    );
}
