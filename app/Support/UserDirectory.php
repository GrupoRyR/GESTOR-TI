<?php

namespace App\Support;

use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Users visible in Administración › Usuarios. Users are not company-scoped
 * models, so the company context is applied here: "Todas" (global users)
 * sees everyone, including users without company (the emergency account);
 * otherwise only users of the current company. Out-of-scope users answer
 * 404 (`ensureVisible`).
 */
class UserDirectory
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * @return Builder<User>
     */
    public function query(): Builder
    {
        $query = User::query();

        if ($this->currentCompany->isAll()) {
            return $query;
        }

        $companyId = $this->currentCompany->id();

        return $companyId === null ? $query->whereRaw('1 = 0') : $query->where('company_id', $companyId);
    }

    public function ensureVisible(User $user): void
    {
        abort_unless($this->query()->whereKey($user->getKey())->exists(), 404);
    }
}
