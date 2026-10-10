<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One audited call to the MCP server (App\Support\Mcp\McpCallRecorder):
 * who (user, personal token or OAuth client), which tool, what it was
 * about (`target`: "GIA-12", "P-3 · T5", "“cartera”") and the result.
 * Never the token value nor what the Gestor answered.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $mcp_token_id
 * @property string|null $oauth_client_id
 * @property string|null $tool
 * @property string|null $target
 * @property string $result
 * @property string|null $message
 * @property Carbon $created_at
 * @property-read McpToken|null $token
 */
class McpCall extends Model
{
    public const UPDATED_AT = null;

    public const RESULT_OK = 'ok';

    /** The tool answered with an error (`isError`) or a JSON-RPC error. */
    public const RESULT_ERROR = 'error';

    /** Refused after authentication: read-only token, no permission, inactive user. */
    public const RESULT_REJECTED = 'rejected';

    /** Expired or revoked personal token (HTTP 401). */
    public const RESULT_UNAUTHORIZED = 'unauthorized';

    protected $fillable = ['user_id', 'mcp_token_id', 'oauth_client_id', 'tool', 'target', 'result', 'message'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(McpToken::class, 'mcp_token_id');
    }
}
