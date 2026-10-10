import { describe, expect, it } from 'vitest';
import { describeNavigation, isNavigationMessage, navigationMessage } from './navigation';

describe('navigation messages', () => {
    it('builds a message the shell can recognise', () => {
        const message = navigationMessage('get', '#admin.users.index');
        expect(isNavigationMessage(message)).toBe(true);
        expect(isNavigationMessage({ type: 'other' })).toBe(false);
        expect(isNavigationMessage('hello')).toBe(false);
        expect(isNavigationMessage(null)).toBe(false);
    });

    it('describes the route without the inert # prefix, adding the method when it is not GET', () => {
        expect(describeNavigation(navigationMessage('get', '#admin.users.index'))).toBe('admin.users.index');
        expect(describeNavigation(navigationMessage('post', '#logout'))).toBe('POST logout');
        expect(describeNavigation(navigationMessage('get', 'https://example.com/a'))).toBe('https://example.com/a');
    });
});
