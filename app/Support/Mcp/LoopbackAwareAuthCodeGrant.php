<?php

namespace App\Support\Mcp;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Authorization code grant of the MCP connector.
 *
 * - PKCE is mandatory with `S256` for every client, public or confidential
 *   (MCP authorization spec; Claude always sends S256). league/oauth2-server
 *   also accepts `plain`, defaults to it when `code_challenge_method` is
 *   missing and skips PKCE for confidential clients: all refused here, so
 *   no auth code is ever bound to a plain challenge.
 * - Loopback redirect URIs match on any port (RFC 8252 §7.3), including
 *   `localhost`: Claude Code registers `http://localhost:<port>/callback`
 *   and later uses another free port. league/oauth2-server only ignores the
 *   port for 127.0.0.1 and [::1]. Only http loopback URIs are relaxed, and
 *   only against registered loopback URIs with the same host and path;
 *   everything else keeps the exact match.
 */
class LoopbackAwareAuthCodeGrant extends AuthCodeGrant
{
    public const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public const CHALLENGE_METHOD = 'S256';

    public function validateAuthorizationRequest(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        // Parent first: an unknown client or redirect keeps its own error.
        $authorizationRequest = parent::validateAuthorizationRequest($request);

        if ($authorizationRequest->getCodeChallenge() === null) {
            throw OAuthServerException::invalidRequest(
                'code_challenge',
                'Se requiere PKCE (code_challenge con code_challenge_method S256). / PKCE with S256 is required.'
            );
        }

        // The parent stores `plain` when the method is missing.
        if ($this->getQueryStringParameter('code_challenge_method', $request) !== self::CHALLENGE_METHOD
            || $authorizationRequest->getCodeChallengeMethod() !== self::CHALLENGE_METHOD) {
            throw OAuthServerException::invalidRequest(
                'code_challenge_method',
                'Solo se admite code_challenge_method S256. / Only the S256 code challenge method is supported.'
            );
        }

        return $authorizationRequest;
    }

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
