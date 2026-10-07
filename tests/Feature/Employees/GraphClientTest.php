<?php

namespace Tests\Feature\Employees;

use App\Services\Microsoft\GraphClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class GraphClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft.client_id' => 'client-id',
            'services.microsoft.client_secret' => 'client-secret',
        ]);

        Sleep::fake();
    }

    private function tokenResponse(string $token = 'token-1', int $expiresIn = 3600): array
    {
        return ['token_type' => 'Bearer', 'expires_in' => $expiresIn, 'access_token' => $token];
    }

    private function tokenRequests(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'login.microsoftonline.com'))->count();
    }

    public function test_token_uses_client_credentials_for_the_tenant(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response($this->tokenResponse())]);

        $this->assertSame('token-1', app(GraphClient::class)->token('tenant-a'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://login.microsoftonline.com/tenant-a/oauth2/v2.0/token'
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret'
            && $request['scope'] === 'https://graph.microsoft.com/.default');
    }

    public function test_token_is_cached_per_tenant_until_sixty_seconds_before_expiry(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::sequence()
            ->push($this->tokenResponse('token-a'))
            ->push($this->tokenResponse('token-b'))
            ->push($this->tokenResponse('token-a2')),
        ]);

        $client = app(GraphClient::class);

        $this->assertSame('token-a', $client->token('tenant-a'));
        $this->assertSame('token-a', $client->token('tenant-a'));
        $this->assertSame('token-b', $client->token('tenant-b'));
        $this->assertSame(2, $this->tokenRequests());

        $this->travel(3600 - 61)->seconds();
        $this->assertSame('token-a', $client->token('tenant-a'));

        $this->travel(2)->seconds();
        $this->assertSame('token-a2', $client->token('tenant-a'));
        $this->assertSame(3, $this->tokenRequests());
    }

    public function test_list_users_follows_next_link_with_the_app_token(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenResponse()),
            'graph.microsoft.com/*' => Http::sequence()
                ->push([
                    'value' => [['id' => 'u1', 'displayName' => 'Ana']],
                    '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users?$skiptoken=page2',
                ])
                ->push(['value' => [['id' => 'u2', 'displayName' => 'Luis'], ['id' => 'u3', 'displayName' => 'Eva']]]),
        ]);

        $users = app(GraphClient::class)->listUsers('tenant-a')->all();

        $this->assertSame(['u1', 'u2', 'u3'], array_column($users, 'id'));

        $graphRequests = Http::recorded(fn (Request $request) => str_contains($request->url(), 'graph.microsoft.com'))
            ->map(fn (array $pair) => $pair[0])
            ->values();

        $this->assertCount(2, $graphRequests);

        $first = urldecode($graphRequests[0]->url());
        $this->assertStringStartsWith('https://graph.microsoft.com/v1.0/users?', $first);
        $this->assertStringContainsString('$select=id,displayName,mail,userPrincipalName,jobTitle,department,accountEnabled,userType', $first);
        $this->assertStringContainsString('$expand=manager($select=id)', $first);
        $this->assertSame('https://graph.microsoft.com/v1.0/users?$skiptoken=page2', $graphRequests[1]->url());
        $this->assertTrue($graphRequests->every(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer token-1')));
    }

    public function test_list_users_retries_on_throttling_honouring_retry_after(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenResponse()),
            'graph.microsoft.com/*' => Http::sequence()
                ->push(['error' => ['code' => 'TooManyRequests']], 429, ['Retry-After' => '3'])
                ->push(['value' => [['id' => 'u1']]]),
        ]);

        $users = app(GraphClient::class)->listUsers('tenant-a')->all();

        $this->assertSame(['u1'], array_column($users, 'id'));
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 3000);
    }

    public function test_retry_after_is_capped(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenResponse()),
            'graph.microsoft.com/*' => Http::sequence()
                ->push([], 503, ['Retry-After' => '3600'])
                ->push(['value' => []]),
        ]);

        app(GraphClient::class)->listUsers('tenant-a')->all();

        Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === GraphClient::MAX_RETRY_AFTER_SECONDS * 1000);
    }

    public function test_gives_up_after_two_retries(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenResponse()),
            'graph.microsoft.com/*' => Http::response([], 500),
        ]);

        try {
            app(GraphClient::class)->listUsers('tenant-a')->all();
            $this->fail('A RequestException was expected.');
        } catch (RequestException $e) {
            $this->assertSame(500, $e->response->status());
        }

        $this->assertCount(3, Http::recorded(fn (Request $request) => str_contains($request->url(), 'graph.microsoft.com')));
    }

    public function test_client_errors_are_not_retried(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenResponse()),
            'graph.microsoft.com/*' => Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403),
        ]);

        $this->expectException(RequestException::class);

        try {
            app(GraphClient::class)->listUsers('tenant-a')->all();
        } finally {
            $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request->url(), 'graph.microsoft.com')));
        }
    }

    public function test_missing_credentials_fail_before_calling_microsoft(): void
    {
        config(['services.microsoft.client_secret' => null]);
        Http::fake();

        $this->expectException(\RuntimeException::class);

        try {
            app(GraphClient::class)->token('tenant-a');
        } finally {
            Http::assertNothingSent();
        }
    }
}
