<?php

namespace App\Support\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * "Probar conexión" (Profile/Mcp): the app calls its own `POST /mcp` the
 * way a client would, without any token (none is created or sent), and
 * expects the OAuth challenge: HTTP 401 with `WWW-Authenticate: Bearer`.
 * That proves the endpoint is reachable over the public URL, served by
 * Laravel and asking for authentication. Result shape: types/mcp.ts
 * McpConnectionTest.
 */
final class ConnectionTester
{
    /**
     * @return array{status: 'ok', ms: int, at: string}|array{status: 'failed', code: string, at: string}
     */
    public function run(string $url): array
    {
        $timeout = (int) config('gestor.mcp.connection_test_timeout', 10);
        $started = hrtime(true);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->withHeaders(['Accept' => 'application/json, text/event-stream'])
                ->post($url, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2025-06-18',
                        'capabilities' => (object) [],
                        'clientInfo' => ['name' => 'gestor-ti-prueba-de-conexion', 'version' => '1.0'],
                    ],
                ]);
        } catch (ConnectionException $e) {
            return $this->failed($this->connectionCode($e->getMessage()));
        }

        $ms = (int) round((hrtime(true) - $started) / 1_000_000);
        $challenge = strtolower((string) $response->header('WWW-Authenticate'));

        if ($response->status() === 401 && str_starts_with($challenge, 'bearer')) {
            return ['status' => 'ok', 'ms' => max(1, $ms), 'at' => now()->toIso8601String()];
        }

        return $this->failed($response->status() === 404 ? 'mcp_not_found' : 'mcp_http_'.$response->status());
    }

    private function connectionCode(string $message): string
    {
        $message = strtolower($message);

        if (str_contains($message, 'curl error 28') || str_contains($message, 'timed out')) {
            return 'mcp_timeout';
        }

        if (preg_match('/curl error (35|51|58|60)\b|ssl|certificate|tls/', $message) === 1) {
            return 'mcp_tls';
        }

        return 'mcp_unreachable';
    }

    /**
     * @return array{status: 'failed', code: string, at: string}
     */
    private function failed(string $code): array
    {
        return ['status' => 'failed', 'code' => $code, 'at' => now()->toIso8601String()];
    }
}
