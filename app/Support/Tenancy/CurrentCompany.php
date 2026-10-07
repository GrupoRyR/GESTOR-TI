<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;

/**
 * Resolves the "current company" context that drives the `CompanyScope`.
 *
 * - Authenticated user WITHOUT `companies.view_all`: always their own
 *   `company_id` (they cannot switch).
 * - Authenticated user WITH `companies.view_all`: the company stored in the
 *   session (`current_company_id`); null means "Todas" (no filter).
 * - No authenticated user (console, jobs, guests): no filter, unless the
 *   context is set explicitly with `runAs()`.
 *
 * The context is resolved on every call (never cached), so it stays correct
 * when several requests are handled by the same application instance.
 */
class CurrentCompany
{
    public const SESSION_KEY = 'current_company_id';

    public const VIEW_ALL_PERMISSION = 'companies.view_all';

    /**
     * Explicit context set by `runAs()`; `$overridden` tells "null = Todas"
     * apart from "no override".
     */
    private bool $overridden = false;

    private ?int $override = null;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Session $session,
    ) {}

    /**
     * Company to filter by, or null when no single company applies
     * (see `isAll()` to tell "Todas" from "nothing visible").
     */
    public function id(): ?int
    {
        if ($this->overridden) {
            return $this->override;
        }

        $user = $this->user();

        return $user ? $this->forUser($user) : null;
    }

    /**
     * True when no company filter applies ("Todas", console, guests).
     * A user without `companies.view_all` and without company is never "all".
     */
    public function isAll(): bool
    {
        if ($this->overridden) {
            return $this->override === null;
        }

        $user = $this->user();

        if ($user === null) {
            return true;
        }

        return $this->canViewAll($user) && $this->forUser($user) === null;
    }

    /**
     * Company context of the given user, or null for "Todas" (only possible
     * with `companies.view_all`) or for a user without company.
     */
    public function forUser(User $user): ?int
    {
        if (! $this->canViewAll($user)) {
            return $user->company_id;
        }

        $selected = $this->session->get(self::SESSION_KEY);

        return $selected === null ? null : (int) $selected;
    }

    /**
     * Stores the selected company (null = "Todas") for the session.
     * Callers must check `companies.view_all` and that the company is active.
     */
    public function set(?int $companyId): void
    {
        $this->session->put(self::SESSION_KEY, $companyId);
    }

    /**
     * Runs the callback with an explicit company context (null = no filter),
     * restoring the previous context afterwards. Intended for jobs and
     * commands that act on behalf of a company.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(?int $companyId, Closure $callback): mixed
    {
        [$previousOverridden, $previousOverride] = [$this->overridden, $this->override];

        $this->overridden = true;
        $this->override = $companyId;

        try {
            return $callback();
        } finally {
            $this->overridden = $previousOverridden;
            $this->override = $previousOverride;
        }
    }

    public function canViewAll(User $user): bool
    {
        return $user->can(self::VIEW_ALL_PERMISSION);
    }

    private function user(): ?User
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user : null;
    }
}
