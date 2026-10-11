<?php

namespace App\Support\Mcp;

use Laravel\Passport\Client;

/**
 * Where the consent screen sends the user back (MCP authorization spec:
 * show the redirect URI host, warn when it is a loopback address because
 * any local process can claim a port). The client name is self-declared at
 * registration, so the host is the trustworthy part.
 *
 * The URI is the request's `redirect_uri` (already validated by the grant
 * before the screen renders) or the client's only registered one.
 */
final class ConsentRedirect
{
    private function __construct(
        public readonly string $host,
        public readonly bool $loopback,
    ) {}

    public static function for(?string $requested, Client $client): ?self
    {
        $uri = $requested ?? ($client->redirect_uris[0] ?? null);
        $host = is_string($uri) ? parse_url($uri, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        return new self($host, in_array($host, LoopbackAwareAuthCodeGrant::LOOPBACK_HOSTS, true));
    }
}
