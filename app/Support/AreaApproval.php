<?php

namespace App\Support;

/**
 * "Visto bueno de área" switch per company: when on, area approvers
 * (`areas.approver_user_id`) approve requests before they reach TI
 * (Stage B). Stored in `Settings` as `areas.approval_enabled.{company_id}`;
 * off by default.
 */
class AreaApproval
{
    public function __construct(private readonly Settings $settings) {}

    public static function key(int $companyId): string
    {
        return 'areas.approval_enabled.'.$companyId;
    }

    public function enabled(int $companyId): bool
    {
        return (bool) $this->settings->get(self::key($companyId), false);
    }

    public function set(int $companyId, bool $enabled): void
    {
        $this->settings->set(self::key($companyId), $enabled);
    }
}
