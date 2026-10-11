<?php

namespace Tests\Feature\GitHub;

use App\Jobs\ProcessGitHubEvent;
use App\Models\GitHubWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `POST /api/github/webhook` (PROMPT §7.2): signed deliveries are stored
 * once and processed on the queue.
 */
class WebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec-test-123';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.webhook_secret' => self::SECRET]);
        Bus::fake([ProcessGitHubEvent::class]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function deliver(string $event, string $body, ?string $signature = 'valid', string $delivery = 'd-1', array $headers = []): TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
        ];

        if ($signature !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature === 'valid'
                ? 'sha256='.hash_hmac('sha256', $body, self::SECRET)
                : $signature;
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/api/github/webhook', [], [], [], $server, $body);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/github/{$name}.json"));
    }

    public function test_a_signed_delivery_is_stored_and_queued(): void
    {
        $this->freezeTime();

        $this->deliver('pull_request', $this->fixture('pull_request'), delivery: 'abc-123')
            ->assertStatus(202)
            ->assertJson(['ok' => true]);

        $event = GitHubWebhookEvent::query()->sole();
        $this->assertSame('abc-123', $event->delivery_id);
        $this->assertSame('pull_request', $event->event);
        $this->assertSame('opened', $event->action);
        $this->assertSame('GrupoRyR/portafolio', $event->repository);
        $this->assertSame(41, $event->payload['number']);
        $this->assertSame('pending', $event->status);
        $this->assertSame(now()->toDateTimeString(), $event->received_at->toDateTimeString());

        Bus::assertDispatched(ProcessGitHubEvent::class, fn (ProcessGitHubEvent $job) => $job->eventId === $event->id);
    }

    public function test_invalid_or_missing_signatures_are_rejected(): void
    {
        $body = $this->fixture('issues');

        $this->deliver('issues', $body, signature: null)->assertUnauthorized();
        $this->deliver('issues', $body, signature: 'sha256='.hash_hmac('sha256', $body, 'otro-secreto'))->assertUnauthorized();
        $this->deliver('issues', $body, signature: 'sha1='.hash_hmac('sha1', $body, self::SECRET))->assertUnauthorized();
        // Signed body changed in transit.
        $this->deliver('issues', $body.' ', signature: 'sha256='.hash_hmac('sha256', $body, self::SECRET))->assertUnauthorized();

        $this->assertSame(0, GitHubWebhookEvent::query()->count());
        Bus::assertNotDispatched(ProcessGitHubEvent::class);
    }

    public function test_it_is_closed_while_the_secret_is_not_configured(): void
    {
        config(['services.github.webhook_secret' => null]);
        $body = $this->fixture('issues');

        $this->deliver('issues', $body, signature: 'sha256='.hash_hmac('sha256', $body, ''))->assertForbidden();

        $this->assertSame(0, GitHubWebhookEvent::query()->count());
    }

    public function test_a_redelivery_is_stored_once(): void
    {
        $body = $this->fixture('issues');

        $this->deliver('issues', $body, delivery: 'same')->assertStatus(202);
        $this->deliver('issues', $body, delivery: 'same')->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, GitHubWebhookEvent::query()->count());
        Bus::assertDispatchedTimes(ProcessGitHubEvent::class, 1);
    }

    public function test_ping_and_unsupported_events_answer_ok_without_storing(): void
    {
        $this->deliver('ping', '{"zen":"Keep it simple.","hook_id":1}')->assertOk()->assertJson(['ok' => true]);
        $this->deliver('star', '{"action":"created"}', delivery: 'd-2')->assertOk()->assertJson(['ignored' => true]);

        $this->assertSame(0, GitHubWebhookEvent::query()->count());
        Bus::assertNotDispatched(ProcessGitHubEvent::class);
    }

    public function test_installation_events_are_stored(): void
    {
        $this->deliver('installation', $this->fixture('installation'))->assertStatus(202);

        $event = GitHubWebhookEvent::query()->sole();
        $this->assertSame('installation', $event->event);
        $this->assertNull($event->repository);
    }

    public function test_malformed_requests_are_rejected(): void
    {
        $this->deliver('issues', 'no es json')->assertStatus(400);
        $this->deliver('', $this->fixture('issues'))->assertStatus(400);

        $this->assertSame(0, GitHubWebhookEvent::query()->count());
    }

    public function test_the_route_is_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('github.webhook');

        $this->assertNotNull($route);
        $this->assertContains('throttle:github-webhook', $route->gatherMiddleware());
    }
}
