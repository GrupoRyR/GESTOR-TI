<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Events\GitHubEventProcessor;
use App\Models\GitHubRepository;
use App\Models\GitHubWebhookEvent;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;

/**
 * Stage D3 fixtures: a connected GitHub App (organization `GrupoRyR`,
 * installation 42), installed repositories and webhook events processed
 * by the real processor (payloads from tests/Fixtures/github).
 */
trait BuildsGitHubWork
{
    use GeneratesAppKey;

    protected const INSTALLATION_TOKEN = 'ghs_installation_token_secret';

    protected function connectGitHub(): void
    {
        $this->generateAppKey();
        config(['services.github.app_id' => '123456']);
        app(Settings::class)->set('github.installation_id', 42);
        app(Settings::class)->set('github.organization', 'GrupoRyR');
    }

    /**
     * Fakes GitHub: the installation token plus the given routes.
     *
     * @param  array<string, mixed>  $routes
     */
    protected function fakeGitHub(array $routes = []): void
    {
        Http::fake($routes + [
            'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => self::INSTALLATION_TOKEN, 'expires_at' => now()->addHour()->toIso8601String()], 201),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function githubRepo(string $name, array $attributes = []): GitHubRepository
    {
        return GitHubRepository::factory()->create([
            'full_name' => "GrupoRyR/{$name}",
            'name' => $name,
            'html_url' => "https://github.com/GrupoRyR/{$name}",
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function fixture(string $name, array $overrides = []): array
    {
        $payload = json_decode((string) file_get_contents(base_path("tests/Fixtures/github/{$name}.json")), true);

        return array_replace_recursive($payload, $overrides);
    }

    /**
     * Stores a delivery and runs the processor on it.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function deliver(string $event, array $payload, ?string $receivedAt = null): GitHubWebhookEvent
    {
        $stored = GitHubWebhookEvent::factory()->create([
            'event' => $event,
            'action' => $payload['action'] ?? null,
            'repository' => $payload['repository']['full_name'] ?? null,
            'payload' => $payload,
            'status' => GitHubWebhookEvent::STATUS_PENDING,
            'received_at' => $receivedAt ?? now(),
        ]);

        app(GitHubEventProcessor::class)->process($stored);

        return $stored->fresh();
    }
}
