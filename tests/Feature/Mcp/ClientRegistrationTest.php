<?php

namespace Tests\Feature\Mcp;

use Laravel\Passport\Client;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Dynamic client registration (RFC 7591) at /oauth/register: Claude's
 * callbacks and loopback redirects (Claude Code) only.
 */
class ClientRegistrationTest extends McpTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedRedirects(): array
    {
        return [
            'claude.ai' => ['https://claude.ai/api/mcp/auth_callback'],
            'claude.com' => ['https://claude.com/api/mcp/auth_callback'],
            'localhost' => ['http://localhost:33418/callback'],
            '127.0.0.1' => ['http://127.0.0.1:51234/callback'],
        ];
    }

    #[DataProvider('acceptedRedirects')]
    public function test_accepted_redirect_uris_register_a_public_client(string $redirect): void
    {
        $response = $this->registerClient([$redirect], 'Claude');

        $response->assertCreated()
            ->assertJson([
                'redirect_uris' => [$redirect],
                'token_endpoint_auth_method' => 'none',
                'scope' => 'mcp:use',
            ]);

        $client = Client::query()->findOrFail($response->json('client_id'));
        $this->assertSame('Claude', $client->name);
        $this->assertFalse($client->confidential());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedRedirects(): array
    {
        return [
            'plain http' => ['http://evil.example.com/callback'],
            'other https host' => ['https://evil.example.com/api/mcp/auth_callback'],
            'look-alike host' => ['https://claude.ai.evil.example.com/callback'],
            'custom scheme' => ['cursor://anysphere.cursor-retrieval/oauth/callback'],
            'userinfo' => ['https://user:pass@claude.ai/api/mcp/auth_callback'],
            'not a url' => ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('rejectedRedirects')]
    public function test_other_redirect_uris_are_rejected(string $redirect): void
    {
        $this->registerClient([$redirect])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_redirect_uri']);

        $this->assertSame(0, Client::query()->count());
    }

    public function test_registration_requires_redirect_uris(): void
    {
        $this->postJson('/oauth/register', ['client_name' => 'Claude'])
            ->assertStatus(400);
    }
}
