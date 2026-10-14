<?php

namespace Tests\Feature\GitHub;

use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubException;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GitHub App client: JWT (RS256 with openssl), cached installation token and
 * the REST calls later stages use. Every call goes through `Http::fake()`.
 */
class GitHubAppTest extends TestCase
{
    use GeneratesAppKey, RefreshDatabase;

    private const TOKEN = 'ghs_installation_token_secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->generateAppKey();
        config(['services.github.app_id' => '123456']);
        app(Settings::class)->set('github.installation_id', 42);
    }

    private function client(): GitHubApp
    {
        return app(GitHubApp::class);
    }

    private function fakeToken(array $routes = []): void
    {
        Http::fake($routes + [
            'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => self::TOKEN, 'expires_at' => now()->addHour()->toIso8601String()], 201),
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function decodeJwt(string $jwt): array
    {
        [$header, $payload, $signature] = explode('.', $jwt);
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));

        return [json_decode($decode($header), true), json_decode($decode($payload), true), $decode($signature), $header.'.'.$payload];
    }

    public function test_without_configuration_it_reports_not_configured(): void
    {
        config(['services.github.app_id' => null]);
        $this->assertFalse($this->client()->isConfigured());

        config(['services.github.app_id' => '123456', 'services.github.private_key_path' => '/missing/key.pem']);
        $this->assertFalse($this->client()->isConfigured());
        $this->assertFalse($this->client()->privateKeyConfigured());

        Http::fake();
        try {
            $this->client()->installationToken();
            $this->fail('Expected a not configured error.');
        } catch (GitHubException $exception) {
            $this->assertSame('not_configured', $exception->reason);
        }
        Http::assertNothingSent();
    }

    public function test_without_an_installation_it_reports_not_connected(): void
    {
        app(Settings::class)->forget('github.installation_id');
        Http::fake();

        $this->assertTrue($this->client()->isConfigured());
        $this->assertNull($this->client()->installationId());

        $this->expectExceptionObject(GitHubException::notConnected());
        $this->client()->installationRepositories();
    }

    public function test_the_jwt_is_rs256_signed_by_the_app_key(): void
    {
        $this->freezeTime();

        [$header, $payload, $signature, $signed] = $this->decodeJwt($this->client()->jwt());

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);
        $this->assertSame('123456', $payload['iss']);
        $this->assertSame(now()->getTimestamp() - 60, $payload['iat']);
        $this->assertLessThanOrEqual(600, $payload['exp'] - $payload['iat']);
        $this->assertGreaterThan(now()->getTimestamp(), $payload['exp']);
        $this->assertSame(1, openssl_verify($signed, $signature, $this->publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_the_installation_token_is_requested_with_the_jwt_and_cached_under_an_hour(): void
    {
        $this->fakeToken();

        $this->assertSame(self::TOKEN, $this->client()->installationToken());
        $this->assertSame(self::TOKEN, $this->client()->installationToken());
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            [, $payload] = $this->decodeJwt(substr($request->header('Authorization')[0], strlen('Bearer ')));

            return $request->method() === 'POST' && $payload['iss'] === '123456';
        });

        $this->travel(56)->minutes();
        $this->client()->installationToken();
        Http::assertSentCount(2);
    }

    public function test_the_token_is_never_cached_in_plain_text(): void
    {
        $this->fakeToken();
        $this->client()->installationToken();

        $cached = cache()->get('github.installation_token.42');
        $this->assertIsString($cached);
        $this->assertStringNotContainsString(self::TOKEN, $cached);
    }

    public function test_the_account_type_defaults_to_a_user_account(): void
    {
        $this->assertSame('user', config('services.github.account_type'));
        $this->assertFalse($this->client()->isOrganization());

        config(['services.github.account_type' => 'organization']);
        $this->assertTrue($this->client()->isOrganization());
    }

    public function test_a_user_account_installation_is_resolved_with_the_jwt(): void
    {
        Http::fake([
            'api.github.com/users/GrupoRyR/installation' => Http::response(['id' => 77]),
            'api.github.com/users/Otra/installation' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->assertSame(77, $this->client()->accountInstallationId('GrupoRyR'));
        $this->assertNull($this->client()->accountInstallationId('Otra'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/orgs/'));
    }

    public function test_an_organization_installation_is_resolved_with_the_jwt(): void
    {
        config(['services.github.account_type' => 'organization']);
        Http::fake([
            'api.github.com/orgs/GrupoRyR/installation' => Http::response(['id' => 77]),
            'api.github.com/orgs/Otra/installation' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->assertSame(77, $this->client()->accountInstallationId('GrupoRyR'));
        $this->assertNull($this->client()->accountInstallationId('Otra'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/users/'));
    }

    public function test_it_lists_every_installation_repository_page(): void
    {
        $page = fn (int $from, int $count) => array_map(fn (int $i) => ['id' => $i, 'full_name' => "GrupoRyR/repo-{$i}"], range($from, $from + $count - 1));
        $this->fakeToken([
            'api.github.com/installation/repositories?per_page=100&page=1' => Http::response(['total_count' => 101, 'repositories' => $page(1, 100)]),
            'api.github.com/installation/repositories?per_page=100&page=2' => Http::response(['total_count' => 101, 'repositories' => $page(101, 1)]),
        ]);

        $repos = $this->client()->installationRepositories();

        $this->assertCount(101, $repos);
        $this->assertSame('GrupoRyR/repo-101', $repos[100]['full_name']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'installation/repositories')
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
    }

    public function test_it_reads_branches_and_file_contents(): void
    {
        $this->fakeToken([
            'api.github.com/repos/GrupoRyR/portafolio/branches*' => Http::response([['name' => 'main'], ['name' => 'gia-12-filtro']]),
            'api.github.com/repos/GrupoRyR/portafolio/contents/CLAUDE.md?ref=main' => Http::response(['type' => 'file', 'encoding' => 'base64', 'content' => chunk_split(base64_encode("# Portafolio\n"), 60)]),
            'api.github.com/repos/GrupoRyR/portafolio/contents/docs*' => Http::response([['type' => 'file', 'path' => 'docs/uso.md']]),
            'api.github.com/repos/GrupoRyR/portafolio/contents/NADA.md' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->assertSame(['main', 'gia-12-filtro'], $this->client()->branches('GrupoRyR/portafolio'));
        $this->assertSame("# Portafolio\n", $this->client()->fileContents('GrupoRyR/portafolio', 'CLAUDE.md', 'main'));
        $this->assertSame([['type' => 'file', 'path' => 'docs/uso.md']], $this->client()->contents('GrupoRyR/portafolio', 'docs'));
        $this->assertNull($this->client()->contents('GrupoRyR/portafolio', 'NADA.md'));
        $this->assertNull($this->client()->fileContents('GrupoRyR/portafolio', 'docs'));
    }

    public function test_it_creates_an_issue(): void
    {
        $this->fakeToken([
            'api.github.com/repos/GrupoRyR/portafolio/issues' => Http::response(['number' => 31, 'html_url' => 'https://github.com/GrupoRyR/portafolio/issues/31'], 201),
        ]);

        $issue = $this->client()->createIssue('GrupoRyR/portafolio', 'GIA-139 · T3 endpoint', 'Cuerpo', ['gestor-ia'], ['leoh']);

        $this->assertSame(31, $issue['number']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/repos/GrupoRyR/portafolio/issues')
            && $request->data() === ['title' => 'GIA-139 · T3 endpoint', 'body' => 'Cuerpo', 'labels' => ['gestor-ia'], 'assignees' => ['leoh']]);
    }

    public function test_on_a_user_account_it_creates_the_repository_for_the_authenticated_user(): void
    {
        $this->fakeToken([
            'api.github.com/user/repos' => Http::response(['id' => 900, 'full_name' => 'GrupoRyR/nuevo', 'default_branch' => 'main'], 201),
            'api.github.com/repos/GrupoRyR/nuevo/contents/*' => Http::response(['content' => []], 201),
        ]);

        $repo = $this->client()->createRepository('GrupoRyR', 'nuevo', ['README.md' => "# Nuevo\n"], 'Proyecto P-4');

        $this->assertSame('GrupoRyR/nuevo', $repo['full_name']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/user/repos'
            && $request['name'] === 'nuevo' && $request['private'] === true);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/orgs/'));
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/repos/GrupoRyR/nuevo/contents/README.md'));
    }

    public function test_on_an_organization_it_creates_a_private_repository_with_its_files(): void
    {
        config(['services.github.account_type' => 'organization']);
        $this->fakeToken([
            'api.github.com/orgs/GrupoRyR/repos' => Http::response(['id' => 900, 'full_name' => 'GrupoRyR/nuevo', 'default_branch' => 'main'], 201),
            'api.github.com/repos/GrupoRyR/nuevo/contents/*' => Http::response(['content' => []], 201),
        ]);

        $repo = $this->client()->createRepository('GrupoRyR', 'nuevo', [
            'README.md' => "# Nuevo\n",
            'CLAUDE.md' => "# Reglas\n",
            'docs/.gitkeep' => '',
        ], 'Proyecto P-4');

        $this->assertSame('GrupoRyR/nuevo', $repo['full_name']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/orgs/GrupoRyR/repos')
            && $request['name'] === 'nuevo' && $request['private'] === true && $request['description'] === 'Proyecto P-4');
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/repos/GrupoRyR/nuevo/contents/CLAUDE.md')
            && base64_decode($request['content']) === "# Reglas\n");
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/contents/docs/.gitkeep'));
    }

    public function test_api_errors_carry_the_status_but_never_the_token(): void
    {
        $this->fakeToken([
            'api.github.com/repos/GrupoRyR/portafolio/issues' => Http::response(['message' => 'Validation Failed'], 422),
        ]);

        try {
            $this->client()->createIssue('GrupoRyR/portafolio', 'x', 'y');
            $this->fail('Expected a request error.');
        } catch (GitHubException $exception) {
            $this->assertSame('github_422', $exception->reason);
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            $this->assertStringNotContainsString(file_get_contents($this->keyPath), $exception->getMessage());
        }
    }
}
