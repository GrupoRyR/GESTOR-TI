<?php

namespace App\Policies;

use App\Models\AiProduct;
use App\Models\User;

/**
 * Who may see or change a Gestor IA product (PROMPT 6.4.2, 6.4.9):
 *
 * - viewAny: users of the Gestor IA module (`ai.requests.create`) and
 *   `ai.manage`. Lists still filter with `AiProduct::visibleTo()`.
 * - view: `ai.manage`, the product owner, and module users whose company
 *   is one of the product's companies.
 * - create / update / delete / manageVersions: `ai.manage`.
 * - viewCosts: `ai.costs.view` (monthly cost, AI spend).
 */
class AiProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ai.requests.create') || $user->can('ai.manage');
    }

    public function view(User $user, AiProduct $product): bool
    {
        if ($user->can('ai.manage') || $this->isOwner($user, $product)) {
            return true;
        }

        return $this->viewAny($user)
            && $user->company_id !== null
            && $product->companies()->whereKey($user->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('ai.manage');
    }

    public function update(User $user, AiProduct $product): bool
    {
        return $user->can('ai.manage');
    }

    public function delete(User $user, AiProduct $product): bool
    {
        return $user->can('ai.manage');
    }

    public function manageVersions(User $user, AiProduct $product): bool
    {
        return $user->can('ai.manage');
    }

    public function viewCosts(User $user, AiProduct $product): bool
    {
        return $product->costVisibleTo($user);
    }

    private function isOwner(User $user, AiProduct $product): bool
    {
        return $product->owner_user_id !== null && (int) $product->owner_user_id === $user->id;
    }
}
