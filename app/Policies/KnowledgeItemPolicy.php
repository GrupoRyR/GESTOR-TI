<?php

namespace App\Policies;

use App\Models\KnowledgeItem;
use App\Models\User;

/**
 * Who may read or change knowledge cards (PROMPT 6.8.4,
 * docs/design/stage-c-contract.md):
 *
 * - viewAny: `assistant.use` (read-only list) or `assistant.knowledge.manage`.
 * - view: viewAny and the card is in `KnowledgeItem::visibleTo()`
 *   (controllers answer 404 otherwise, so hidden cards are not disclosed).
 * - create / update / delete / review: `assistant.knowledge.manage`
 *   (routes) and, for an existing card, that it is visible.
 */
class KnowledgeItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(KnowledgeItem::USE_PERMISSION) || KnowledgeItem::canManage($user);
    }

    public function view(User $user, KnowledgeItem $item): bool
    {
        return $this->viewAny($user) && $item->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return KnowledgeItem::canManage($user);
    }

    public function update(User $user, KnowledgeItem $item): bool
    {
        return KnowledgeItem::canManage($user) && $item->isVisibleTo($user);
    }

    public function delete(User $user, KnowledgeItem $item): bool
    {
        return $this->update($user, $item);
    }
}
