<?php

namespace App\Console\Commands;

use App\Models\McpCall;
use Illuminate\Console\Command;

/**
 * Deletes `mcp_calls` older than `gestor.mcp.calls_retention_days` (the
 * retention Profile/Mcp shows). Tokens are never deleted. Scheduled daily
 * (routes/console.php).
 */
class McpPruneCallsCommand extends Command
{
    protected $signature = 'mcp:prune-calls';

    protected $description = 'Borra el registro de llamadas del conector MCP más antiguo que el tiempo de retención';

    public function handle(): int
    {
        $days = (int) config('gestor.mcp.calls_retention_days', 180);

        if ($days < 1) {
            $this->info('Retención sin configurar: no se borró nada.');

            return self::SUCCESS;
        }

        $deleted = McpCall::query()->where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Llamadas del conector borradas: {$deleted}");

        return self::SUCCESS;
    }
}
