<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\Client;

/**
 * Deletes OAuth clients of the MCP connector that registered themselves
 * (DCR, no owner) more than PRUNE_AFTER_HOURS ago and never got an
 * authorization code or a token. Claude registers a new client on every
 * fresh connection, so `/oauth/register` alone would grow the table
 * without bound. Clients that were ever used are kept (their tokens and
 * audit entries point to them). Scheduled daily (routes/console.php).
 */
class McpPruneClientsCommand extends Command
{
    public const PRUNE_AFTER_HOURS = 24;

    protected $signature = 'mcp:prune-clients';

    protected $description = 'Borra los clientes OAuth del conector MCP registrados hace más de 24 horas que nunca recibieron un token';

    public function handle(): int
    {
        $deleted = Client::query()
            ->whereNull('owner_id')
            ->where('created_at', '<', now()->subHours(self::PRUNE_AFTER_HOURS))
            ->whereDoesntHave('tokens')
            ->whereDoesntHave('authCodes')
            ->delete();

        $this->info("Clientes OAuth sin uso borrados: {$deleted}");

        return self::SUCCESS;
    }
}
