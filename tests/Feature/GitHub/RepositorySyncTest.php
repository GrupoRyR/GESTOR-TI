<?php

namespace Tests\Feature\GitHub;

use App\Models\AiProduct;
use App\Models\GitHubRepository;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\RepositorySync;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Installed repositories (`github_repositories`) refreshed from the GitHub
 * App installation of the saved organization.
 */
class RepositorySyncTest extends TestCase
{
    use GeneratesAppKey, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generateAppKey();
        config(['services.github.app_id' => '1']);
        app(Settings::class)->set('github.organization', 'GrupoRyR');
    }

    private function repo(int $id, string $name, array $extra = []): array
    {
        return $extra + [
            'id' => $id,
            'full_name' => "GrupoRyR/{$name}",
            'name' => $name,
            'private' => true,
            'html_url' => "https://github.com/GrupoRyR/{$name}",
            'default_branch' => 'main',
            'language' => 'PHP',
            'pushed_at' => '2026-10-07T13:40:00Z',
        ];
    }

    public function test_it_resolves_the_installation_and_upserts_the_repositories(): void
    {
        $this->freezeTime();
        $product = AiProduct::factory()->create();
        $linked = GitHubRepository::factory()->create(['github_id' => 2, 'full_name' => 'GrupoRyR/viejo', 'name' => 'viejo']);
        $linked->linkTo($product, 'main', linkedBy: null);
        GitHubRepository::factory()->create(['github_id' => 3, 'full_name' => 'GrupoRyR/borrado', 'name' => 'borrado']);

        Http::fake([
            'api.github.com/orgs/GrupoRyR/installation' => Http::response(['id' => 42]),
            'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            'api.github.com/installation/repositories*' => Http::response(['total_count' => 2, 'repositories' => [
                $this->repo(1, 'portafolio', ['language' => 'TypeScript']),
                $this->repo(2, 'renombrado', ['default_branch' => 'develop', 'private' => false]),
            ]]),
        ]);

        $this->assertSame(2, app(RepositorySync::class)->sync());

        $this->assertSame(42, app(Settings::class)->get('github.installation_id'));
        $portafolio = GitHubRepository::query()->where('github_id', 1)->sole();
        $this->assertSame('GrupoRyR/portafolio', $portafolio->full_name);
        $this->assertSame('TypeScript', $portafolio->language);
        $this->assertSame(['main'], $portafolio->branches);
        $this->assertSame(42, $portafolio->installation_id);
        $this->assertSame(now()->toDateTimeString(), $portafolio->last_synced_at->toDateTimeString());

        // Renamed repositories keep their link (matched by GitHub id).
        $renamed = $linked->fresh();
        $this->assertSame('GrupoRyR/renombrado', $renamed->full_name);
        $this->assertSame('develop', $renamed->default_branch);
        $this->assertFalse($renamed->private);
        $this->assertTrue($renamed->linkable->is($product));

        // Unlinked repositories the app lost are removed.
        $this->assertDatabaseMissing('github_repositories', ['github_id' => 3]);
    }

    public function test_it_needs_the_organization_installation(): void
    {
        Http::fake(['api.github.com/orgs/GrupoRyR/installation' => Http::response(['message' => 'Not Found'], 404)]);

        try {
            app(RepositorySync::class)->sync();
            $this->fail('Expected not connected.');
        } catch (GitHubException $exception) {
            $this->assertSame('not_connected', $exception->reason);
        }
    }
}
