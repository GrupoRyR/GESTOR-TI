<?php

namespace App\Policies;

use App\Models\AiDocument;
use App\Models\AiProduct;
use App\Models\AiProject;
use App\Models\User;

/**
 * Who may open or change a document (PROMPT 6.4.4, ai-knowledge.ts
 * "Permissions"). Documentation pages are IT pages in Stage B:
 *
 * - viewAny / view: `ai.manage`; view also needs the owner in the current
 *   company context (AiDocument::visibleTo: the project through
 *   CompanyScope, the product through its companies).
 * - create: `ai.docs.edit`; update (autosave, publish, restore): view +
 *   `ai.docs.edit`.
 *
 * `Doc.visibility` (company | it | project) is shown and kept for when
 * documentation opens to the areas; today only IT reads it.
 */
class AiDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ai.manage');
    }

    public function view(User $user, AiDocument $document): bool
    {
        if (! $user->can('ai.manage')) {
            return false;
        }

        if ($document->ai_project_id !== null) {
            return AiProject::query()->whereKey($document->ai_project_id)->exists();
        }

        return AiProduct::query()->visibleTo($user)->whereKey($document->ai_product_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('ai.manage') && $user->can('ai.docs.edit');
    }

    public function update(User $user, AiDocument $document): bool
    {
        return $this->view($user, $document) && $user->can('ai.docs.edit');
    }
}
