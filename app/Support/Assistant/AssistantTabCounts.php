<?php

namespace App\Support\Assistant;

use App\Models\KnowledgeDraft;
use App\Models\KnowledgeItem;
use App\Models\UnansweredQuestion;
use App\Models\User;

/**
 * Counts after the AssistantLayout tab labels (`unanswered_count`,
 * `drafts_count` of every Asistente TI page): open unanswered questions
 * and pending `/aprender` drafts; 0 without `assistant.knowledge.manage`.
 */
final class AssistantTabCounts
{
    /**
     * @return array{unanswered_count: int, drafts_count: int}
     */
    public function for(User $user): array
    {
        if (! KnowledgeItem::canManage($user)) {
            return ['unanswered_count' => 0, 'drafts_count' => 0];
        }

        return [
            'unanswered_count' => UnansweredQuestion::query()->open()->count(),
            'drafts_count' => KnowledgeDraft::query()->pending()->count(),
        ];
    }
}
