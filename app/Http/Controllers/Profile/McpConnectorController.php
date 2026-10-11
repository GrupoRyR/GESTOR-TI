<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureMcpAccess;
use App\Http\Requests\Profile\StoreMcpTokenRequest;
use App\Models\McpCall;
use App\Models\McpToken;
use App\Models\User;
use App\Support\Mcp\ConnectionTester;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Conector para Claude" (`Profile/Mcp`, types/pages/profile-mcp.ts):
 * personal tokens of the MCP server, their activity and "Probar conexión".
 *
 * - Only `ai.mcp.use`; each person lists, creates and revokes their own
 *   tokens. Without it the page shows who has it and the other routes
 *   answer 403.
 * - The token value travels once: flashed (encrypted) by `tokens.store`
 *   and rendered by the next GET as `created_token`. Every response here
 *   is history-encrypted and `Cache-Control: no-store`.
 */
class McpConnectorController extends Controller
{
    public const NO_STORE = 'no-store, no-cache, private, max-age=0';

    /** Session keys: the one-time created token, the last connection test. */
    private const CREATED = 'mcp.created_token';

    private const TEST = 'mcp.connection_test';

    /** Calls listed in the activity panel (newest first). */
    public const ACTIVITY_LIMIT = 100;

    /** "Rechazadas en tus otros tokens" window. */
    public const REJECTED_ELSEWHERE_DAYS = 7;

    /** Tools of types/mcp.ts McpToolKey (calls to `quien_soy` are not listed). */
    private const LISTED_TOOLS = ['buscar_solicitudes', 'ver_solicitud', 'crear_tarea', 'actualizar_tarea', 'agregar_nota', 'adjuntar_documento'];

    public function __construct(private readonly ConnectionTester $tester) {}

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $allowed = $user->can(EnsureMcpAccess::PERMISSION);
        [$selected, $panel] = $allowed ? $this->selection($request, $user) : [null, false];
        $created = $allowed ? $this->createdToken($request, $user) : null;

        Inertia::encryptHistory();

        return $this->noStore(Inertia::render('Profile/Mcp', [
            'server' => $this->server(),
            'tokens' => $allowed ? $this->tokens($user) : [],
            'connection_test' => $allowed ? $request->session()->get(self::TEST) : null,
            'created_token' => $created,
            'selected_token' => fn () => $selected === null ? null : $this->token($selected),
            'activity' => fn () => $panel ? $this->activity($user, $selected) : null,
            'mcp_holders' => User::permission(EnsureMcpAccess::PERMISSION)
                ->where('active', true)->orderBy('name')->pluck('name')->values()->all(),
            'now' => now()->toIso8601String(),
        ])->toResponse($request));
    }

    public function store(StoreMcpTokenRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        ['token' => $token, 'value' => $value] = McpToken::issue($user, $data['name'], $data['scope'], (int) $data['expires_in_days']);

        activity('auth')
            ->causedBy($user)
            ->performedOn($token)
            ->event('mcp_personal_token_created')
            ->withProperties(['name' => $token->name, 'scope' => $token->scope, 'expires_at' => $token->expires_at->toDateString()])
            ->log("Token personal del conector creado: {$token->name}");

        $request->session()->flash(self::CREATED, Crypt::encryptString((string) json_encode([
            'id' => $token->id,
            'value' => $value,
        ])));
        unset($value);

        return $this->noStore(redirect()->route('profile.mcp'));
    }

    public function destroy(Request $request, McpToken $token): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can(EnsureMcpAccess::PERMISSION), 403);
        abort_unless((int) $token->user_id === $user->id, 404);

        if (! $token->isRevoked()) {
            $token->forceFill(['revoked_at' => now(), 'revoked_by' => $user->id])->save();

            activity('auth')
                ->causedBy($user)
                ->performedOn($token)
                ->event('mcp_personal_token_revoked')
                ->withProperties(['name' => $token->name, 'scope' => $token->scope])
                ->log("Token personal del conector revocado: {$token->name}");
        }

        return $this->noStore($this->backToPage($request));
    }

    public function test(Request $request): Response
    {
        abort_unless($request->user()?->can(EnsureMcpAccess::PERMISSION), 403);

        $request->session()->put(self::TEST, $this->tester->run($this->server()['url']));

        return $this->noStore($this->backToPage($request));
    }

    /**
     * @return array{url: string, active: bool}
     */
    private function server(): array
    {
        return [
            'url' => rtrim((string) config('app.url'), '/').'/mcp',
            'active' => (bool) config('gestor.mcp.enabled', true),
        ];
    }

    /**
     * `?token={id}|all`: the selected token (own only, else 404) and
     * whether the activity panel is open.
     *
     * @return array{0: ?McpToken, 1: bool}
     */
    private function selection(Request $request, User $user): array
    {
        $param = $request->query('token');

        if ($param === null || $param === '') {
            return [null, false];
        }

        if ($param === 'all') {
            return [null, true];
        }

        abort_unless(is_string($param) && ctype_digit($param), 404);
        $token = McpToken::query()->where('user_id', $user->id)->find((int) $param);
        abort_unless($token instanceof McpToken, 404);

        return [$token, true];
    }

    /**
     * The token flashed by `store`, read once (only its owner's).
     *
     * @return array{id: int, name: string, value: string, scope: string, expires_at: string}|null
     */
    private function createdToken(Request $request, User $user): ?array
    {
        $flashed = $request->session()->get(self::CREATED);

        if (! is_string($flashed)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($flashed), true);
        } catch (DecryptException) {
            return null;
        }

        $token = is_array($data) ? McpToken::query()->where('user_id', $user->id)->find($data['id'] ?? 0) : null;

        if (! $token instanceof McpToken || ! is_string($data['value'] ?? null)
            || ! hash_equals($token->token_hash, McpToken::hashOf($data['value']))) {
            return null;
        }

        return [
            'id' => $token->id,
            'name' => $token->name,
            'value' => $data['value'],
            'scope' => $token->scope,
            'expires_at' => $token->expires_at->toDateString(),
        ];
    }

    /**
     * Live tokens first, then revoked ones; newest first in each group.
     *
     * @return list<array<string, mixed>>
     */
    private function tokens(User $user): array
    {
        return McpToken::query()
            ->where('user_id', $user->id)
            ->orderByRaw('revoked_at is null desc')
            ->orderByDesc('id')
            ->get()
            ->map(fn (McpToken $token) => $this->token($token))
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, scope: string, last4: string, created_at: string, last_used_at: ?string, last_client: ?string, expires_at: string, revoked_at: ?string}
     */
    private function token(McpToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'scope' => $token->scope,
            'last4' => $token->last_four,
            'created_at' => $token->created_at->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'last_client' => $token->last_client,
            'expires_at' => $token->expires_at->toDateString(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
        ];
    }

    /**
     * Calls of the user's personal tokens (one token or all of them).
     * OAuth calls (claude.ai) are audited too but have no token to show.
     *
     * @return array{token_id: ?int, calls: list<array<string, mixed>>, rejected_elsewhere: list<array<string, mixed>>, retention: ?string}
     */
    private function activity(User $user, ?McpToken $selected): array
    {
        $tokenIds = McpToken::query()->where('user_id', $user->id)->pluck('id');
        $calls = fn (Collection $ids) => McpCall::query()
            ->with('token:id,name')
            ->whereIn('mcp_token_id', $ids)
            ->whereIn('tool', self::LISTED_TOOLS)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $listed = $calls($selected === null ? $tokenIds : collect([$selected->id]))
            ->limit(self::ACTIVITY_LIMIT)
            ->get();

        $elsewhere = $selected === null ? collect() : $calls($tokenIds->reject(fn ($id) => (int) $id === $selected->id)->values())
            ->whereIn('result', [McpCall::RESULT_REJECTED, McpCall::RESULT_UNAUTHORIZED])
            ->where('created_at', '>=', now()->subDays(self::REJECTED_ELSEWHERE_DAYS))
            ->limit(self::ACTIVITY_LIMIT)
            ->get();

        $days = (int) config('gestor.mcp.calls_retention_days', 180);

        return [
            'token_id' => $selected?->id,
            'calls' => $listed->map(fn (McpCall $call) => $this->call($call))->values()->all(),
            'rejected_elsewhere' => $elsewhere->map(fn (McpCall $call) => $this->call($call))->values()->all(),
            'retention' => $days > 0 ? ($days === 1 ? '1 día' : "{$days} días") : null,
        ];
    }

    /**
     * @return array{id: int, tool: string, summary: string, at: string, token: array{id: int, name: string}, result: string, reason: ?string}
     */
    private function call(McpCall $call): array
    {
        $ok = $call->result === McpCall::RESULT_OK;
        $summary = $call->target ?? '—';

        if ($ok && $call->message !== null) {
            $summary .= ' · '.$call->message;
        }

        return [
            'id' => $call->id,
            'tool' => (string) $call->tool,
            'summary' => $summary,
            'at' => $call->created_at->toIso8601String(),
            'token' => ['id' => (int) $call->mcp_token_id, 'name' => (string) $call->token?->name],
            // `unauthorized` (expired or revoked token) reads as rejected.
            'result' => match ($call->result) {
                McpCall::RESULT_OK => 'ok',
                McpCall::RESULT_ERROR => 'error',
                default => 'rejected',
            },
            'reason' => $ok ? null : $call->message,
        ];
    }

    /**
     * Back to the page, keeping an open activity panel (`?token=`) when the
     * request came from it.
     */
    private function backToPage(Request $request): RedirectResponse
    {
        $previous = (string) $request->headers->get('referer', '');
        $page = route('profile.mcp');
        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;

        if (str_starts_with($previous, $page) && is_string($token) && ($token === 'all' || ctype_digit($token))) {
            return redirect()->route('profile.mcp', ['token' => $token]);
        }

        return redirect()->route('profile.mcp');
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', self::NO_STORE);

        return $response;
    }
}
