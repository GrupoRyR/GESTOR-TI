<?php

namespace App\Support\Mcp;

use App\Domain\Ai\SecretGuard;
use App\Models\McpCall;
use App\Models\McpToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes `mcp_calls` (App\Http\Middleware\AuditMcpCalls, AuthenticateMcp):
 * the tool, what it was about and the result. The target is built from
 * the call's arguments (keys, the search text), each piece dropped when it
 * looks like a credential or a token; the answer is only read for counts
 * and the new task key, never stored.
 */
final class McpCallRecorder
{
    public const OMITTED = '[omitido]';

    public const TARGET_LENGTH = 120;

    public const MESSAGE_LENGTH = 160;

    public function __construct(private readonly SecretGuard $secrets) {}

    /**
     * A `tools/call` that went through the server (or was refused on the way).
     */
    public function afterCall(Request $request, McpContext $context, Response $response): void
    {
        [$tool, $arguments] = $this->toolCall($request);
        $answer = null;
        $message = null;
        $status = $response->getStatusCode();

        if ($status === 403) {
            $result = McpCall::RESULT_REJECTED;
            $reason = $request->attributes->get(McpContext::REJECTION);
            $message = is_string($reason) ? $reason : 'acceso denegado';
        } elseif ($status !== 200) {
            $result = McpCall::RESULT_ERROR;
            $message = "HTTP {$status}";
        } else {
            $body = json_decode((string) $response->getContent(), true);
            $text = is_array($body) ? data_get($body, 'result.content.0.text') : null;

            if (! is_array($body) || isset($body['error'])) {
                $result = McpCall::RESULT_ERROR;
                $message = is_array($body) ? (string) data_get($body, 'error.message', 'error') : 'respuesta no válida';
            } elseif (data_get($body, 'result.isError') === true) {
                $result = McpCall::RESULT_ERROR;
                $message = is_string($text) ? $text : 'error';
            } else {
                $result = McpCall::RESULT_OK;
                $answer = is_string($text) ? json_decode($text, true) : null;
                $message = $this->okMessage($tool, is_array($answer) ? $answer : []);
            }
        }

        $this->write([
            'user_id' => $context->user->id,
            'mcp_token_id' => $context->token?->id,
            'oauth_client_id' => $context->oauthClientId,
            'tool' => $tool,
            'target' => $this->target($tool, $arguments, is_array($answer) ? $answer : []),
            'result' => $result,
            'message' => $message,
        ]);
    }

    /**
     * A known personal token that no longer works (HTTP 401).
     */
    public function unauthorized(Request $request, McpToken $token, string $reason): void
    {
        [$tool, $arguments] = $this->toolCall($request);

        $this->write([
            'user_id' => $token->user_id,
            'mcp_token_id' => $token->id,
            'tool' => $tool,
            'target' => $this->target($tool, $arguments, []),
            'result' => McpCall::RESULT_UNAUTHORIZED,
            'message' => $reason,
        ]);
    }

    /**
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function toolCall(Request $request): array
    {
        if ($request->json('method') !== 'tools/call') {
            return [null, []];
        }

        $tool = $request->json('params.name');
        $arguments = $request->json('params.arguments');

        return [
            is_string($tool) ? Str::limit($this->safe($tool), 60, '') : null,
            is_array($arguments) ? $arguments : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $answer
     */
    private function target(?string $tool, array $arguments, array $answer): ?string
    {
        $key = fn (string $name): ?string => is_string($arguments[$name] ?? null) && trim($arguments[$name]) !== ''
            ? $this->safe(strtoupper(trim($arguments[$name])))
            : null;
        $text = fn (string $name): ?string => is_scalar($arguments[$name] ?? null) && trim((string) $arguments[$name]) !== ''
            ? $this->safe(Str::squish((string) $arguments[$name]))
            : null;
        $join = fn (?string ...$parts): ?string => ($parts = array_values(array_filter($parts, fn ($part) => $part !== null))) === []
            ? null
            : implode(' · ', $parts);

        $target = match ($tool) {
            'ver_solicitud' => $key('clave'),
            'buscar_solicitudes' => $join(
                ($search = $text('texto')) !== null ? "“{$search}”" : null,
                $text('estado'), $text('producto'), $text('area'), $text('empresa'),
            ),
            'crear_tarea' => $join($key('proyecto'), is_string($answer['tarea'] ?? null) ? $this->safe($answer['tarea']) : null),
            'actualizar_tarea', 'agregar_nota' => $join($key('proyecto'), $key('tarea')),
            'adjuntar_documento' => ($document = $text('documento_id')) !== null
                ? "documento {$document}"
                : $join($key('proyecto') ?? $text('producto'), $text('titulo')),
            default => null,
        };

        return $target === null ? null : Str::limit($target, self::TARGET_LENGTH - 3);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function okMessage(?string $tool, array $answer): ?string
    {
        if ($tool === 'buscar_solicitudes' && is_int($answer['total'] ?? null)) {
            return $answer['total'] === 1 ? '1 resultado' : "{$answer['total']} resultados";
        }

        return null;
    }

    /**
     * The piece as given, or OMITTED when it looks like a credential or a
     * connector token.
     */
    private function safe(string $piece): string
    {
        return stripos($piece, McpToken::PREFIX) !== false || $this->secrets->find($piece) !== null ? self::OMITTED : $piece;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes): void
    {
        if (is_string($attributes['message'] ?? null)) {
            $attributes['message'] = Str::limit($this->safe($attributes['message']), self::MESSAGE_LENGTH - 3);
        }

        McpCall::query()->create($attributes);
    }
}
