<?php

namespace App\Policies;

use App\Models\AiRequirement;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;

/**
 * Requirements to users (PROMPT 6.4.3):
 *
 * - respond (open `Ai/Requirements/Respond`, answer, pass it to a
 *   coworker): the recipient (any company; the page carries no costs, no
 *   internal notes, no task names) or IT (`ai.manage` with access to the
 *   project's company), who may record an answer received elsewhere.
 * - manage (create, remind): IT with access to the company.
 */
class AiRequirementPolicy
{
    public function __construct(private readonly CurrentCompany $companies) {}

    public function respond(User $user, AiRequirement $requirement): bool
    {
        return $this->isRecipient($user, $requirement) || $this->manage($user, $requirement);
    }

    public function manage(User $user, AiRequirement $requirement): bool
    {
        return $user->can('ai.manage') && $this->companies->canAccess($user, (int) $requirement->company_id);
    }

    public function isRecipient(User $user, AiRequirement $requirement): bool
    {
        return $requirement->recipient_id !== null && (int) $requirement->recipient_id === $user->id;
    }
}
