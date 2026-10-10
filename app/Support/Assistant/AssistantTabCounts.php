<?php

namespace App\Support\Assistant;

use App\Models\KnowledgeItem;
use App\Models\User;

/**
 * Counts after the AssistantLayout tab labels (`unanswered_count`,
 * `drafts_count` of every Asistente TI page): 0 without
 * `assistant.knowledge.manage`.
 *
 * TODO(C2): count pending `/aprender` drafts and open unanswered questions
 * once their tables exist; until then both are 0.
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

        return ['unanswered_count' => 0, 'drafts_count' => 0];
    }
}
