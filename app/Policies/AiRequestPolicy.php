<?php

namespace App\Policies;

use App\Enums\Ai\AreaApprovalStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\User;
use App\Support\Settings;
use App\Support\Tenancy\CurrentCompany;

/**
 * Who may do what with a Gestor IA request (PROMPT 6.4, 6.4.9):
 *
 * - view: the requester (and whoever filed it), +1 voters (they follow it),
 *   `ai.manage` (with access to the company), project approvers
 *   (`ai.projects.approve`, global), the area approver of its area, and
 *   `ai.area.view` holders whose employee area is the request's area.
 * - viewInternal / evaluate / transition: `ai.manage` with company access.
 *   Internal notes are never shown to anyone else.
 * - create: `ai.requests.create`; createOnBehalf: `ai.manage`.
 * - vote: `ai.requests.create`, not the requester, request still open.
 * - comment: whoever can view, while the request is open (`ai.manage` always).
 * - approveArea: the area approver of the request's area (with
 *   `ai.requests.approve_area`) while the area step is pending.
 * - approveProject: `ai.projects.approve` (only the "Aprobador de proyectos"
 *   role; Súper Admin does not inherit it) while pending approval; when
 *   Settings `ai.approval.require_other_if_evaluator` is on, not the person
 *   who evaluated it.
 * - validate: the requester while "En pruebas".
 */
class AiRequestPolicy
{
    public function __construct(
        private readonly CurrentCompany $companies,
        private readonly Settings $settings,
    ) {}

    public function view(User $user, AiRequest $request): bool
    {
        if ($this->isRequester($user, $request) || $this->manages($user, $request)) {
            return true;
        }

        if ($user->can('ai.projects.approve') || $this->isAreaApprover($user, $request)) {
            return true;
        }

        if ($user->can('ai.area.view') && $this->sameArea($user, $request)) {
            return true;
        }

        return $request->votes()->where('user_id', $user->id)->exists();
    }

    public function viewInternal(User $user, AiRequest $request): bool
    {
        return $this->manages($user, $request);
    }

    public function create(User $user): bool
    {
        return $user->can('ai.requests.create');
    }

    public function createOnBehalf(User $user): bool
    {
        return $user->can('ai.manage');
    }

    public function evaluate(User $user, AiRequest $request): bool
    {
        return $this->manages($user, $request);
    }

    public function transition(User $user, AiRequest $request): bool
    {
        return $this->manages($user, $request);
    }

    public function vote(User $user, AiRequest $request): bool
    {
        return $user->can('ai.requests.create')
            && (int) $request->requester_id !== $user->id
            && ! $request->status->isFinished();
    }

    public function comment(User $user, AiRequest $request): bool
    {
        if ($this->manages($user, $request)) {
            return true;
        }

        return ! $request->status->isFinished() && $this->view($user, $request);
    }

    public function commentInternal(User $user, AiRequest $request): bool
    {
        return $this->manages($user, $request);
    }

    public function approveArea(User $user, AiRequest $request): bool
    {
        return $request->area_approval_status === AreaApprovalStatus::Pending
            && $user->can('ai.requests.approve_area')
            && $this->isAreaApprover($user, $request);
    }

    public function approveProject(User $user, AiRequest $request): bool
    {
        if ($request->status !== RequestStatus::PendingApproval || ! $user->can('ai.projects.approve')) {
            return false;
        }

        $otherRequired = (bool) $this->settings->get('ai.approval.require_other_if_evaluator', false);

        return ! ($otherRequired && (int) $request->evaluated_by_id === $user->id);
    }

    public function validate(User $user, AiRequest $request): bool
    {
        return (int) $request->requester_id === $user->id && $request->status === RequestStatus::InTesting;
    }

    private function manages(User $user, AiRequest $request): bool
    {
        return $user->can('ai.manage') && $this->companies->canAccess($user, $request->company_id);
    }

    private function isRequester(User $user, AiRequest $request): bool
    {
        return (int) $request->requester_id === $user->id || (int) $request->created_by_id === $user->id;
    }

    private function isAreaApprover(User $user, AiRequest $request): bool
    {
        return $request->area_id !== null && (int) $request->area?->approver_user_id === $user->id;
    }

    private function sameArea(User $user, AiRequest $request): bool
    {
        $employee = $user->employee;

        return $request->area_id !== null
            && $employee !== null
            && (int) $employee->company_id === (int) $request->company_id
            && (int) $employee->area_id === (int) $request->area_id;
    }
}
