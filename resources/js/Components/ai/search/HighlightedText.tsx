import { cx } from '@/Components/ui/utils';
import { highlightSegments, type HighlightRange } from './highlight';

/**
 * Text with the matched parts in bold and underlined (never color alone),
 * from the server's ranges. Text is rendered as React text nodes, never HTML.
 */
export function Highlight({ text, highlights, className }: { text: string; highlights: readonly HighlightRange[]; className?: string }) {
    return (
        <span className={className}>
            {highlightSegments(text, highlights).map((segment, index) =>
                segment.match ? (
                    <mark
                        key={index}
                        className={cx('rounded-[3px] bg-status-maintenance-bg px-px font-bold text-fg underline decoration-2 underline-offset-[3px]')}
                    >
                        {segment.text}
                    </mark>
                ) : (
                    <span key={index}>{segment.text}</span>
                ),
            )}
        </span>
    );
}
