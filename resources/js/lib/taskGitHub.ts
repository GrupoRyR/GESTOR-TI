/**
 * Why a task moved by itself. The server moves a task to "En revisión" when
 * an open PR mentions its request key (see lib/githubKeys); the card and the
 * task page say so in words.
 */
import type { GithubLink, KanbanColumnKey } from '../types/ai';
import { normalizeRequestKey } from './githubKeys';

/** "En revisión porque el PR #41 menciona GIA-139"; null when it does not apply. */
export function reviewReason(column: KanbanColumnKey, pr: GithubLink['pr'], requestKey: string | null): string | null {
    if (column !== 'review' || !pr || !requestKey) return null;
    if (pr.state !== 'open' && pr.state !== 'review') return null;
    const key = normalizeRequestKey(requestKey) ?? requestKey;
    return `En revisión porque el PR #${pr.number} menciona ${key}`;
}
