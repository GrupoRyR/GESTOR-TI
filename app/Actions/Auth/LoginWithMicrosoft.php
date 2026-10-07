<?php

namespace App\Actions\Auth;

use App\Actions\Users\AssignInitialRoles;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Resolves the local user of a Microsoft (Entra ID) sign-in.
 *
 * Only tenants of ACTIVE companies are accepted (the company comes from the
 * token `tid`); the email domain must belong to that company and guest
 * accounts are rejected. The user is matched by `azure_id`, then by email
 * (linking the `azure_id`), or created. Name, tenant, company and
 * `last_login_at` are refreshed on every sign-in, and the initial roles are
 * (re)applied (AssignInitialRoles is additive and idempotent). A not yet
 * linked employee of the company with the same azure id or email is linked.
 *
 * It does not authenticate the session: the caller logs the user in.
 *
 * @throws MicrosoftLoginRejected
 */
class LoginWithMicrosoft
{
    public function __construct(private readonly AssignInitialRoles $assignInitialRoles) {}

    public function handle(MicrosoftIdentity $identity): User
    {
        if (trim($identity->azureId) === '') {
            throw new MicrosoftLoginRejected('missing_identity');
        }

        if ($identity->external) {
            throw new MicrosoftLoginRejected('external_account');
        }

        $company = $this->companyFor($identity->tenantId);
        $email = strtolower(trim($identity->email));

        if (! $this->domainAllowed($email, $company)) {
            throw new MicrosoftLoginRejected('domain_not_allowed');
        }

        $user = DB::transaction(function () use ($identity, $company, $email): User {
            $user = User::where('azure_id', $identity->azureId)->first()
                ?? User::where('email', $email)->first();

            if ($user !== null && $user->azure_id !== null && $user->azure_id !== $identity->azureId) {
                throw new MicrosoftLoginRejected('email_linked_to_other_account');
            }

            $user ??= new User([
                'email' => $email,
                // Unusable: Microsoft users never sign in with a password, and
                // the emergency login only accepts its configured email.
                'password' => Hash::make(Str::random(64)),
                'avatar_url' => $identity->avatarUrl,
            ]);

            $user->forceFill([
                'name' => $identity->name !== '' ? $identity->name : $email,
                'azure_id' => $identity->azureId,
                'tenant_id' => $identity->tenantId,
                'company_id' => $company->id,
                'last_login_at' => now(),
            ])->save();

            $this->linkEmployee($user, $company, $email);

            return $user;
        });

        return $this->assignInitialRoles->handle($user);
    }

    /**
     * Links the user to the employee of the same company with the same
     * `azure_id` (preferred) or email, when neither is linked yet.
     */
    private function linkEmployee(User $user, Company $company, string $email): void
    {
        $alreadyLinked = Employee::withoutGlobalScopes()->where('user_id', $user->id)->exists();

        if ($alreadyLinked) {
            return;
        }

        $employee = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('user_id')
            ->where(fn ($query) => $query
                ->where('azure_id', $user->azure_id)
                ->orWhereRaw('lower(email) = ?', [$email]))
            ->orderByRaw('case when azure_id = ? then 0 else 1 end', [$user->azure_id])
            ->first();

        $employee?->update(['user_id' => $user->id]);
    }

    private function companyFor(?string $tenantId): Company
    {
        $tenantId = strtolower(trim((string) $tenantId));

        $company = $tenantId === ''
            ? null
            : Company::query()->active()->whereRaw('lower(tenant_id) = ?', [$tenantId])->first();

        return $company ?? throw new MicrosoftLoginRejected('tenant_not_allowed');
    }

    /**
     * Fails closed: a company without configured domains admits nobody.
     */
    private function domainAllowed(string $email, Company $company): bool
    {
        $domain = Str::after($email, '@');

        if ($domain === '' || $domain === $email) {
            return false;
        }

        $domains = array_map(
            static fn (string $d): string => strtolower(trim($d)),
            $company->domains ?? [],
        );

        return in_array($domain, $domains, true);
    }
}
