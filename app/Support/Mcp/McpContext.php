<?php

namespace App\Support\Mcp;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who is calling the MCP server, set by App\Http\Middleware\AuthenticateMcp
 * on the request: the user and either their personal token or the OAuth
 * client of claude.ai. OAuth tokens (`mcp:use`) act as `read_write`.
 */
final class McpContext
{
    public const ATTRIBUTE = 'mcp.context';

    /** Why a call was refused after authentication (shown as the call's reason). */
    public const REJECTION = 'mcp.rejection';

    /** Read tools: what a `read` personal token may call. */
    public const READ_TOOLS = ['quien_soy', 'buscar_solicitudes', 'ver_solicitud'];

    /** Write tools: only `read_write` tokens and OAuth. */
    public const WRITE_TOOLS = ['crear_tarea', 'actualizar_tarea', 'agregar_nota', 'adjuntar_documento'];

    public function __construct(
        public readonly User $user,
        public readonly ?McpToken $token = null,
        public readonly ?string $oauthClientId = null,
    ) {}

    public static function of(Request $request): ?self
    {
        $context = $request->attributes->get(self::ATTRIBUTE);

        return $context instanceof self ? $context : null;
    }

    public static function current(): ?self
    {
        return app()->bound('request') ? self::of(request()) : null;
    }

    public function scope(): string
    {
        return $this->token?->scope ?? McpToken::SCOPE_READ_WRITE;
    }

    public function canWrite(): bool
    {
        return $this->scope() === McpToken::SCOPE_READ_WRITE;
    }

    public static function isWriteTool(?string $tool): bool
    {
        return in_array($tool, self::WRITE_TOOLS, true);
    }

    /** Rate limit key: one bucket per personal token, else per user. */
    public function rateKey(): string
    {
        return $this->token !== null ? 'token:'.$this->token->id : 'user:'.$this->user->id;
    }
}
