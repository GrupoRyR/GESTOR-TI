<?php

namespace Tests\Feature\Mcp;

/**
 * Discovery documents Claude reads before the OAuth flow: RFC 9728
 * protected-resource metadata (its `resource` must equal the server URL
 * exactly), RFC 8414 authorization-server metadata and the 401 challenge.
 */
class OAuthDiscoveryTest extends McpTestCase
{
    public function test_protected_resource_metadata_names_the_server_url(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp/gestor-ia')
            ->assertOk()
            ->assertJson([
                'resource' => url(self::SERVER_PATH),
                'authorization_servers' => [url('/')],
                'scopes_supported' => ['mcp:use'],
            ]);

        $this->assertSame(rtrim((string) config('app.url'), '/').'/mcp/gestor-ia', url(self::SERVER_PATH));
    }

    public function test_authorization_server_metadata_advertises_pkce_and_registration(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJson([
                'issuer' => url('/'),
                'authorization_endpoint' => url('/oauth/authorize'),
                'token_endpoint' => url('/oauth/token'),
                'registration_endpoint' => url('/oauth/register'),
                'response_types_supported' => ['code'],
                'code_challenge_methods_supported' => ['S256'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
            ]);
    }

    public function test_server_without_token_answers_401_with_resource_metadata(): void
    {
        $response = $this->initialize();

        $response->assertUnauthorized();
        $this->assertSame(
            'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/gestor-ia').'"',
            $response->headers->get('WWW-Authenticate'),
        );
    }

    public function test_server_with_an_invalid_token_answers_401(): void
    {
        $this->initialize('not-a-token')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    }

    public function test_get_on_the_server_is_not_a_stream(): void
    {
        $this->get(self::SERVER_PATH)->assertStatus(405)->assertHeader('Allow', 'POST');
    }
}
