<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\AuthCode;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/**
 * Withdraws a user's access to the MCP connector (the consent screen tells
 * users to ask IT for it): revokes their access tokens, the refresh tokens
 * of those tokens and their pending authorization codes, for every client
 * or only `--client`. Passport only serves the connector, so every OAuth
 * token of the user is an MCP token. The next MCP call answers 401 and
 * the refresh fails (`invalid_grant`); Claude has to ask for consent again.
 *
 * Audited in the `auth` log (sensitive): `mcp_access_revoked`.
 */
class McpRevokeCommand extends Command
{
    protected $signature = 'mcp:revoke
        {email : Correo del usuario}
        {--client= : Solo este cliente OAuth (client_id)}';

    protected $description = 'Revoca el acceso de un usuario al conector MCP del Gestor IA (tokens, refresh tokens y códigos pendientes)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $client = $this->option('client');
        $client = is_string($client) && trim($client) !== '' ? trim($client) : null;

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            $this->error("No existe un usuario con el correo {$email}.");

            return self::FAILURE;
        }

        [$tokens, $codes] = DB::transaction(function () use ($user, $client): array {
            $tokenIds = Token::query()
                ->where('user_id', $user->getKey())
                ->when($client !== null, fn ($query) => $query->where('client_id', $client))
                ->pluck('id');

            RefreshToken::query()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
            $tokens = Token::query()->whereIn('id', $tokenIds)->where('revoked', false)->update(['revoked' => true]);

            $codes = AuthCode::query()
                ->where('user_id', $user->getKey())
                ->when($client !== null, fn ($query) => $query->where('client_id', $client))
                ->where('revoked', false)
                ->update(['revoked' => true]);

            return [$tokens, $codes];
        });

        activity('auth')
            ->performedOn($user)
            ->event('mcp_access_revoked')
            ->withProperties([
                'source' => 'console',
                'client_id' => $client,
                'tokens' => $tokens,
                'auth_codes' => $codes,
            ])
            ->log('Acceso al conector MCP revocado para '.$user->email);

        $this->info("Acceso al conector MCP revocado para {$user->email}: {$tokens} token(s) y {$codes} código(s) pendiente(s).");

        return self::SUCCESS;
    }
}
