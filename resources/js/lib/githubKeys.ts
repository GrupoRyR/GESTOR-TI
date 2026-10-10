/**
 * Request keys inside GitHub data. The server links a webhook event (PR,
 * issue, push) to a request when its title, body or branch name mentions
 * `GIA-123`, case-insensitively (`gia-123-arreglar-filtro` counts). The
 * backend mirrors these rules; keep both in sync.
 */
import { slugify } from './slug';

/** `GIA-` + digits, not glued to letters/digits on either side. */
const KEY_PATTERN = /(?<![A-Za-z0-9])gia-(\d+)(?![A-Za-z0-9])/gi;

/** The description part keeps its first five words, at most 40 characters. */
const BRANCH_DESCRIPTION_WORDS = 5;
const BRANCH_DESCRIPTION_MAX = 40;

export interface KeySources {
    title?: string | null;
    body?: string | null;
    branch?: string | null;
}

/** "gia-139" → "GIA-139"; null when it is not a single key. */
export function normalizeRequestKey(value: string): string | null {
    const match = /^\s*gia-(\d+)\s*$/i.exec(value);
    return match ? `GIA-${match[1]}` : null;
}

/** Every key mentioned in title, body and branch, upper-cased, first seen first, no repeats. */
export function extractRequestKeys({ title, body, branch }: KeySources): string[] {
    const keys: string[] = [];
    for (const source of [title, body, branch]) {
        if (!source) continue;
        for (const match of source.matchAll(KEY_PATTERN)) {
            const key = `GIA-${match[1]}`;
            if (!keys.includes(key)) keys.push(key);
        }
    }
    return keys;
}

/**
 * Branch name the Gestor proposes: `gia-139-t4`, plus a short slug of the
 * description when given (`gia-123-arreglar-filtro`).
 */
export function suggestedBranch(key: string, task?: { key: string } | null, description?: string | null): string {
    const parts = [key.trim().toLowerCase()];
    if (task) parts.push(slugify(task.key));
    if (description) {
        const slug = slugify(description)
            .split('-')
            .slice(0, BRANCH_DESCRIPTION_WORDS)
            .join('-')
            .slice(0, BRANCH_DESCRIPTION_MAX)
            .replace(/-+$/, '');
        if (slug) parts.push(slug);
    }
    return parts.join('-');
}
