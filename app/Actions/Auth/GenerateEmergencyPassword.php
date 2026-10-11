<?php

namespace App\Actions\Auth;

use Illuminate\Support\Str;

/**
 * Strong password for the emergency account: 32 characters with letters,
 * numbers and symbols, no spaces. Resolved from the container so tests can
 * pin the value.
 */
class GenerateEmergencyPassword
{
    public const LENGTH = 32;

    public function __invoke(): string
    {
        return Str::password(self::LENGTH);
    }
}
