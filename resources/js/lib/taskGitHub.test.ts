import { describe, expect, it } from 'vitest';
import { reviewReason } from './taskGitHub';

describe('reviewReason', () => {
    it('explains why the task is in review when an open PR mentions the request key', () => {
        expect(reviewReason('review', { number: 41, state: 'review' }, 'GIA-139')).toBe('En revisión porque el PR #41 menciona GIA-139');
        expect(reviewReason('review', { number: 41, state: 'open' }, 'gia-139')).toBe('En revisión porque el PR #41 menciona GIA-139');
    });

    it('says nothing outside review, without a PR, or once the PR is merged or closed', () => {
        expect(reviewReason('in_progress', { number: 41, state: 'open' }, 'GIA-139')).toBeNull();
        expect(reviewReason('review', null, 'GIA-139')).toBeNull();
        expect(reviewReason('review', { number: 36, state: 'merged' }, 'GIA-139')).toBeNull();
        expect(reviewReason('review', { number: 40, state: 'closed' }, 'GIA-139')).toBeNull();
    });

    it('says nothing without a request key', () => {
        expect(reviewReason('review', { number: 41, state: 'open' }, null)).toBeNull();
    });
});
