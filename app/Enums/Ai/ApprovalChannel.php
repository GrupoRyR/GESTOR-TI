<?php

namespace App\Enums\Ai;

/**
 * Where an approval decision was taken.
 */
enum ApprovalChannel: string
{
    case Web = 'web';
    case Telegram = 'telegram';
}
