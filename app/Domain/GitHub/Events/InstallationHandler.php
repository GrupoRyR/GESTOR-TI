<?php

namespace App\Domain\GitHub\Events;

use App\Models\GitHubRepository;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\RepositorySync;
use App\Support\Settings;

/**
 * `installation` and `installation_repositories`: the app was installed,
 * changed or uninstalled on the organization. Installs and repository
 * changes refresh `github_repositories` (RepositorySync, which also stores
 * the installation id, so the admin page shows GitHub connected); an
 * uninstall forgets the installation (links and events are kept).
 * Installations on another account than `github.organization` are
 * ignored; with no organization saved yet, the installing account is
 * taken as the organization.
 */
class InstallationHandler implements EventHandler
{
    public function __construct(
        private readonly RepositorySync $sync,
        private readonly Settings $settings,
        private readonly GitHubApp $github,
    ) {}

    public function needsLinkedRepository(): bool
    {
        return false;
    }

    public function handle(string $action, array $payload, ?GitHubRepository $repository): Outcome
    {
        $installation = is_array($payload['installation'] ?? null) ? $payload['installation'] : [];
        $account = (string) ($installation['account']['login'] ?? '');
        $installationId = (int) ($installation['id'] ?? 0);
        $organization = $this->settings->get('github.organization');

        if (is_string($organization) && $organization !== '') {
            if ($account !== '' && strcasecmp($organization, $account) !== 0) {
                return Outcome::ignored();
            }
        } elseif ($account !== '') {
            $this->settings->set('github.organization', $account);
        }

        if ($action === 'deleted') {
            if ($installationId > 0) {
                $this->github->forgetInstallationToken($installationId);
            }
            $this->settings->forget('github.installation_id');

            return Outcome::processed();
        }

        if ($action === 'suspend') {
            return Outcome::ignored();
        }

        $this->sync->sync();

        return Outcome::processed();
    }
}
