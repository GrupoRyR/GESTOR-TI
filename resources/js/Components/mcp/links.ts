import { route } from '@/lib/route';

const ACTIVITY_ONLY = ['activity', 'selected_token'];

/** Inertia Link props for a partial reload that opens the activity panel of one token (or `'all'`). */
export function activityLinkProps(tokenId: number | 'all') {
    return {
        href: route('profile.mcp', { token: tokenId }),
        only: ACTIVITY_ONLY,
        preserveScroll: true,
        preserveState: true,
    };
}
