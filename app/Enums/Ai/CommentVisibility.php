<?php

namespace App\Enums\Ai;

/**
 * Public conversation (`comments`) or IT-only notes (`internal_notes`).
 */
enum CommentVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';
}
