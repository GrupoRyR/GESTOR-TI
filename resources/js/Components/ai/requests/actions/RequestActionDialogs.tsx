import { useCallback, useState } from 'react';
import type { Estimate, RequestSummary } from '@/types/ai';
import type { TriageActionData } from '@/types/pages/ai-triage';
import type { Company } from '@/types/shared';
import { AskInfoDialog } from './AskInfoDialog';
import { ConvertToProjectDialog } from './ConvertToProjectDialog';
import { MergeDialog } from './MergeDialog';
import { RejectDialog } from './RejectDialog';
import { SendToApprovalDialog } from './SendToApprovalDialog';

export type RequestAction = 'ask' | 'reject' | 'merge' | 'approval' | 'convert';

export interface RequestActionsState {
    action: RequestAction | null;
    /** Kept after closing so the closing dialog still has its request. */
    targets: RequestSummary[];
    /** MergeDialog preselection. */
    mergeInto: string | null;
}

/** Which dialog is open and for which requests (one, or several for "Pedir información"). */
export function useRequestActions() {
    const [state, setState] = useState<RequestActionsState>({ action: null, targets: [], mergeInto: null });
    const open = useCallback((action: RequestAction, targets: RequestSummary[], mergeInto: string | null = null) => {
        setState({ action, targets, mergeInto });
    }, []);
    const close = useCallback(() => setState((previous) => ({ ...previous, action: null })), []);
    return { state, open, close };
}

export interface RequestActionDialogsProps {
    actions: ReturnType<typeof useRequestActions>;
    data: TriageActionData;
    companies: Company[];
    canViewCosts: boolean;
    viewerId: number;
    /** Estimate to start "Mandar a aprobación" from (IT detail). */
    estimate?: Estimate | null;
    /** Results shown before any search in "Unir con otra" (duplicates, the similar one). */
    mergeFallback?: RequestSummary[];
}

/** Every action dialog of a request, mounted once per page (inbox, IT detail). */
export function RequestActionDialogs({ actions, data, companies, canViewCosts, viewerId, estimate, mergeFallback = [] }: RequestActionDialogsProps) {
    const { state, open, close } = actions;
    const first = state.targets[0];
    if (!first) return null;
    const is = (action: RequestAction) => state.action === action;
    const candidates = data.merge_candidates ?? mergeFallback;

    return (
        <>
            <AskInfoDialog open={is('ask')} onClose={close} requests={state.targets} />
            <RejectDialog open={is('reject')} onClose={close} request={first} />
            <MergeDialog
                open={is('merge')}
                onClose={close}
                request={first}
                candidates={candidates}
                preselect={state.mergeInto ?? first.similar_to?.key ?? null}
                companies={companies}
            />
            <SendToApprovalDialog
                open={is('approval')}
                onClose={close}
                request={first}
                team={data.team}
                approvers={data.approvers}
                estimate={estimate}
                canViewCosts={canViewCosts}
                viewerId={viewerId}
            />
            <ConvertToProjectDialog open={is('convert')} onClose={close} request={first} onSendToApproval={() => open('approval', [first])} />
        </>
    );
}
