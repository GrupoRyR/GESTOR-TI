/**
 * Search helpers: split a result text into plain and matched segments from
 * the server's `[start, end)` ranges (UTF-16 offsets, end exclusive), and the
 * `type` filter parameter of `ai.search`.
 */

export interface TextSegment {
    text: string;
    match: boolean;
}

export type HighlightRange = readonly [number, number];

/**
 * Segments covering the whole `text`, in order. Ranges are sorted, clamped
 * to the text, and overlapping or touching ranges are merged; empty,
 * reversed or out-of-range ones are ignored.
 */
export function highlightSegments(text: string, ranges: readonly HighlightRange[]): TextSegment[] {
    if (text === '') return [];

    const clean = ranges
        .map(([start, end]) => [Math.max(0, Math.min(start, text.length)), Math.max(0, Math.min(end, text.length))] as const)
        .filter(([start, end]) => end > start)
        .sort((a, b) => a[0] - b[0]);

    const merged: [number, number][] = [];
    for (const [start, end] of clean) {
        const last = merged[merged.length - 1];
        if (last && start <= last[1]) last[1] = Math.max(last[1], end);
        else merged.push([start, end]);
    }

    const segments: TextSegment[] = [];
    let cursor = 0;
    for (const [start, end] of merged) {
        if (start > cursor) segments.push({ text: text.slice(cursor, start), match: false });
        segments.push({ text: text.slice(start, end), match: true });
        cursor = end;
    }
    if (cursor < text.length) segments.push({ text: text.slice(cursor), match: false });
    return segments;
}

export type SearchType = 'docs' | 'requests' | 'products';

export const SEARCH_TYPES: readonly SearchType[] = ['docs', 'requests', 'products'];

/** `type` query value: omitted when every type (or none) is selected, else a comma list in a stable order. */
export function searchTypeParam(selected: readonly SearchType[]): string | undefined {
    const ordered = SEARCH_TYPES.filter((type) => selected.includes(type));
    if (ordered.length === 0 || ordered.length === SEARCH_TYPES.length) return undefined;
    return ordered.join(',');
}

/** Types selected by a `type` query value; every type when it is missing or empty. */
export function parseSearchTypes(param: string | null): SearchType[] {
    if (!param) return [...SEARCH_TYPES];
    const asked = param.split(',').map((part) => part.trim());
    const types = SEARCH_TYPES.filter((type) => asked.includes(type));
    return types.length > 0 ? types : [...SEARCH_TYPES];
}
