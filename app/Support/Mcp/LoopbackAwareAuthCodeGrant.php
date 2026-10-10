<?php

namespace App\Support\Mcp;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Authorization code grant that matches loopback redirect URIs on any port
 * (RFC 8252 §7.3), including `localhost`: Claude Code registers
 * `http://localhost:<port>/callback` and later uses another free port.
 * league/oauth2-server only ignores the port for 127.0.0.1 and [::1].
 *
 * Only http loopback URIs are relaxed, and only against registered loopback
 * URIs with the same host and path; everything else keeps the exact match.
 */
class LoopbackAwareAuthCodeGrant extends AuthCodeGrant
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    protected function validateRedirectUri(
        string $redirectUri,
        ClientEntityInterface $client,
        ServerRequestInterface $request
    ): void {
        $requested = self::withoutLoopbackPort($redirectUri);

        if ($requested !== null) {
            foreach ((array) $client->getRedirectUri() as $registered) {
                if (self::withoutLoopbackPort($registered) === $requested) {
                    return;
                }
            }
        }

        parent::validateRedirectUri($redirectUri, $client, $request);
    }

    /**
     * The URI without its port when it is an http loopback URI, else null.
     */
    public static function withoutLoopbackPort(string $uri): ?string
    {
        $parts = parse_url($uri);

        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'http'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! in_array(strtolower($parts['host'] ?? ''), self::LOOPBACK_HOSTS, true)) {
            return null;
        }

        return 'http://'.strtolower($parts['host']).($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
