import { KnowledgeForm } from '@/Components/assistant/knowledge/KnowledgeForm';
import { AssistantLayout } from '@/Layouts/AssistantLayout';
import { route } from '@/lib/route';
import type { KnowledgeCreateProps } from '@/types/pages/assistant-knowledge';
import type { PageProps } from '@/types/shared';

/** Nueva ficha, blank or prefilled from an unanswered question or a /aprender draft (C-KnowledgeForm). */
export default function KnowledgeCreate(props: PageProps<KnowledgeCreateProps>) {
    return (
        <AssistantLayout
            shared={props}
            title="Nueva ficha · Base de conocimiento"
            tab="knowledge"
            counts={{ drafts: props.drafts_count, unanswered: props.unanswered_count }}
            breadcrumb={[{ label: 'Base de conocimiento', href: route('assistant.knowledge.index') }, { label: 'Nueva ficha' }]}
        >
            <KnowledgeForm {...props} mode="create" currentCompanyId={props.current_company_id} />
        </AssistantLayout>
    );
}
