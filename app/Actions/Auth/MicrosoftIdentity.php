<?php

namespace App\Actions\Auth;

/**
 * Normalized Microsoft identity, mapped from Socialite by the controller so
 * LoginWithMicrosoft can be tested without HTTP.
 *
 * `external` flags guest/B2B accounts (UPN with `#EXT#`), which are never
 * allowed to sign in.
 */
final readonly class MicrosoftIdentity
{
    public function __construct(
        public string $azureId,
        public string $email,
        public string $name,
        public ?string $tenantId,
        public ?string $avatarUrl = null,
        public bool $external = false,
    ) {}
}
