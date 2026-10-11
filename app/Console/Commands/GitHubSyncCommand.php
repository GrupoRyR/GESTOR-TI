<?php

namespace App\Console\Commands;

use App\Services\GitHub\GitHubException;
use App\Services\GitHub\RepositorySync;
use Illuminate\Console\Command;

/**
 * Refreshes the installed repositories (`github_repositories`) and the
 * installation id from the GitHub App, the same as the `installation`
 * webhooks do. Run after installing the app or when a repository is
 * missing in Integraciones › GitHub.
 */
class GitHubSyncCommand extends Command
{
    protected $signature = 'github:sync';

    protected $description = 'Sincroniza los repositorios donde está instalada la GitHub App';

    public function handle(RepositorySync $sync): int
    {
        try {
            $count = $sync->sync();
        } catch (GitHubException $error) {
            $this->error(match ($error->reason) {
                'not_configured' => 'La GitHub App no está configurada: revisa GITHUB_APP_ID y GITHUB_PRIVATE_KEY_PATH en el .env.',
                'not_connected' => 'GitHub no está conectado: guarda la organización en Integraciones › GitHub e instala la app en ella.',
                default => "GitHub rechazó la sincronización ({$error->reason}).",
            });

            return self::FAILURE;
        }

        $this->info("Repositorios sincronizados: {$count}.");

        return self::SUCCESS;
    }
}
