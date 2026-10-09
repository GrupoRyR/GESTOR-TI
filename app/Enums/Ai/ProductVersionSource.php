<?php

namespace App\Enums\Ai;

/**
 * Where a product version came from: typed by TI or imported from a
 * GitHub release (Stage D).
 */
enum ProductVersionSource: string
{
    case Manual = 'manual';
    case GithubRelease = 'github_release';
}
