import { ApprovalSummaryCard, DecisionForm, DoneView } from '@/Components/ai/triage/ApprovalParts';
import { firstName } from '@/Components/ai/triage/triage';
import { Icon } from '@/Components/ui/Icon';
import { NoPermission } from '@/Components/ui/NoPermission';
import { GestorLayout } from '@/Layouts/GestorLayout';
import { can } from '@/lib/can';
import { route } from '@/lib/route';
import type { ApprovalsShowProps } from '@/types/pages/ai-triage';
import type { PageProps } from '@/types/shared';

/**
 * One approval, phone-first (B-ApprovalPhone): it opens from the Telegram
 * button. After deciding, `decision` turns it into the done screen
 * (B-ApprovalDone). On desktop it stays a centered column.
 */
export default function ApprovalsShow(props: PageProps<ApprovalsShowProps>) {
    const { approval, from_telegram, decision, wip, auth, companies } = props;
    const { request } = approval;
    const isIt = can(auth.user, 'ai.manage');
    const canViewCosts = can(auth.user, 'ai.costs.view');
    const company = companies.find((item) => item.id === request.company_id);
    const breadcrumb = [
        ...(isIt ? [{ label: 'Bandeja', href: route('ai.inbox.index') }] : []),
        { label: 'Aprobaciones', href: route('ai.approvals.index') },
        { label: request.key },
    ];
    const title = `Aprobar ${request.key} · Gestor IA`;

    if (!can(auth.user, 'ai.projects.approve')) {
        return (
            <GestorLayout shared={props} title={title} tab={isIt ? 'inbox' : undefined} breadcrumb={breadcrumb}>
                <NoPermission
                    title="Solo los aprobadores de proyectos deciden aquí"
                    description="Las aprobaciones las dan Jhon Castro o Edward Ramanan. Si debes aprobar proyectos, pídele el rol a un administrador del Gestor TI."
                />
            </GestorLayout>
        );
    }

    return (
        <GestorLayout shared={props} title={title} tab={isIt ? 'inbox' : undefined} breadcrumb={breadcrumb}>
            <div className="mx-auto flex w-full max-w-[560px] flex-col gap-4">
                {decision ? (
                    <DoneView
                        detail={approval}
                        result={decision.result}
                        at={decision.at}
                        comment={decision.comment}
                        viewerName={auth.user.name}
                        canViewCosts={canViewCosts}
                        wip={wip}
                    />
                ) : (
                    <>
                        <div className="flex flex-col gap-1.5">
                            {from_telegram ? (
                                <span className="inline-flex items-center gap-1.5 self-start text-xs font-semibold text-fg-muted">
                                    <Icon name="telegram" size={14} />
                                    Desde Telegram
                                </span>
                            ) : null}
                            <p className="text-[15px] text-fg-soft">
                                {firstName(auth.user.name)}, {approval.approval.requested_by.name} te pide aprobar este proyecto.
                            </p>
                        </div>
                        <ApprovalSummaryCard detail={approval} company={company} canViewCosts={canViewCosts} headingLevel={1} />
                        <DecisionForm requestKey={request.key} approvers={approval.approval.approvers} viewerId={auth.user.id} />
                    </>
                )}
            </div>
        </GestorLayout>
    );
}
