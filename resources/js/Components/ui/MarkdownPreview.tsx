import { useMemo } from 'react';
import { renderMarkdown } from '@/lib/markdown';
import { cx } from './utils';

export interface MarkdownPreviewProps {
    source: string;
    /** Text shown when `source` is empty. */
    emptyText?: string;
    className?: string;
}

/**
 * Renders Markdown through lib/markdown (raw HTML escaped, unsafe URLs
 * refused, links with rel="noopener noreferrer"). Styles: `.md-prose`.
 */
export function MarkdownPreview({ source, emptyText = 'Nada para mostrar todavía.', className }: MarkdownPreviewProps) {
    const html = useMemo(() => renderMarkdown(source), [source]);
    if (html === '') return <p className={cx('text-sm text-fg-muted', className)}>{emptyText}</p>;
    // Safe: renderMarkdown never emits raw HTML from the source (html: false).
    return <div className={cx('md-prose', className)} dangerouslySetInnerHTML={{ __html: html }} />;
}
