<?php

namespace App\Services\GitHub;

use RuntimeException;

/**
 * A GitHub call that could not be made or was refused. `reason` is a short
 * code safe to show or log (`not_configured`, `not_connected`,
 * `github_<status>`, `github_connection`); messages never carry the
 * private key, the JWT or a token.
 */
class GitHubException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('not_configured', 'The GitHub App is not configured (GITHUB_APP_ID and a readable GITHUB_PRIVATE_KEY_PATH).');
    }

    public static function notConnected(): self
    {
        return new self('not_connected', 'The GitHub App is not installed on the organization.');
    }

    public static function requestFailed(int $status, string $endpoint): self
    {
        return new self('github_'.$status, "GitHub answered {$status} to {$endpoint}.");
    }

    public static function connectionFailed(string $endpoint): self
    {
        return new self('github_connection', "Could not reach GitHub ({$endpoint}).");
    }
}
