<?php

namespace App\Models\Exceptions;

use LogicException;

/**
 * Thrown when an exclusive permission (config/permissions.php, e.g.
 * `ai.vault.access`) is attached to a role, or granted directly to a user
 * outside its dedicated action.
 */
class ExclusivePermissionException extends LogicException
{
    public static function forRole(string $permission): self
    {
        return new self("The exclusive permission [{$permission}] cannot be attached to a role.");
    }

    public static function forDirectGrant(string $permission): self
    {
        return new self("The exclusive permission [{$permission}] can only be granted through its dedicated action.");
    }
}
