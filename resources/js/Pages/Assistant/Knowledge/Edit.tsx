import { KnowledgeForm } from '@/Components/assistant/knowledge/KnowledgeForm';
import { AssistantLayout } from '@/Layouts/AssistantLayout';
import { route } from '@/lib/route';
import type { KnowledgeEditProps } from '@/types/pages/assistant-knowledge';
import type { PageProps } from '@/types/shared';

/** Editar ficha: the same form with the type fixed, plus "Eliminar ficha" (C-KnowledgeForm). */
export default function KnowledgeEdit(props: PageProps<KnowledgeEditProps>) {
    const { card } = props;
    return (
        <AssistantLayout
            shared={props}
            title={`Editar ${card.key} · Base de conocimiento`}
            tab="knowledge"
            counts={{ drafts: props.drafts_count, unanswered: props.unanswered_count }}
            breadcrumb={[
                { label: 'Base de conocimiento', href: route('assistant.knowledge.index') },
                { label: card.key, href: route('assistant.knowledge.show', { card: card.key }) },
                { label: 'Editar' },
            ]}
        >
            <KnowledgeForm {...props} mode="edit" currentCompanyId={props.current_company_id} />
        </AssistantLayout>
    );
}
