<?php

namespace App\Services\GitHub;

use App\Models\GitHubRepository;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes `github_repositories` from the app installation on the saved
 * account (`github.organization`, a user or an organization), and stores the installation id
 * (`github.installation_id`, which makes GitHub "connected").
 *
 * Repositories are matched by GitHub id, so renames keep their link.
 * Repositories the installation lost are removed unless linked to a
 * product or project (the link and its history stay).
 */
class RepositorySync
{
    public function __construct(
        private readonly GitHubApp $github,
        private readonly Settings $settings,
    ) {}

    /**
     * @return int repositories the installation can access
     */
    public function sync(): int
    {
        $organization = $this->settings->get('github.organization');
        if (! is_string($organization) || $organization === '') {
            throw GitHubException::notConnected();
        }

        $installationId = $this->github->accountInstallationId($organization) ?? throw GitHubException::notConnected();
        $this->settings->set('github.installation_id', $installationId);

        $repositories = $this->github->installationRepositories();
        $now = now();

        DB::transaction(function () use ($repositories, $installationId, $now): void {
            $seen = [];

            foreach ($repositories as $data) {
                $seen[] = (int) $data['id'];
                $this->upsert($data, $installationId, $now);
            }

            GitHubRepository::query()
                ->whereNotIn('github_id', $seen)
                ->whereNull('linkable_id')
                ->delete();
        });

        return count($repositories);
    }

    /**
     * @param  array<string, mixed>  $data  GitHub repository object
     */
    private function upsert(array $data, int $installationId, Carbon $now): void
    {
        $repository = GitHubRepository::query()->firstOrNew(['github_id' => (int) $data['id']]);
        $defaultBranch = (string) ($data['default_branch'] ?? 'main');

        $repository->fill([
            'full_name' => (string) $data['full_name'],
            'name' => (string) $data['name'],
            'default_branch' => $defaultBranch,
            'private' => (bool) ($data['private'] ?? true),
            'html_url' => (string) $data['html_url'],
            'language' => isset($data['language']) ? (string) $data['language'] : null,
            'pushed_at' => isset($data['pushed_at']) ? Carbon::parse((string) $data['pushed_at']) : null,
            'installation_id' => $installationId,
            'last_synced_at' => $now,
        ]);
        $repository->branches = array_values(array_unique([$defaultBranch, ...($repository->branches ?? [])]));
        $repository->save();
    }
}
