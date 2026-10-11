<?php

namespace App\Actions\Auth;

use RuntimeException;

/**
 * A Microsoft sign-in that is not allowed into the Gestor TI. `reason` is a
 * stable code for logs; the user always sees the same Spanish message.
 */
class MicrosoftLoginRejected extends RuntimeException
{
    public const MESSAGE = 'Tu organización no tiene acceso al Gestor TI.';

    /** The local user was deactivated in Administración › Usuarios. */
    public const ACCOUNT_INACTIVE = 'account_inactive';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGE);
    }
}
