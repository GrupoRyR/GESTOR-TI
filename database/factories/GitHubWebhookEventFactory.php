<?php

namespace Database\Factories;

use App\Models\GitHubWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GitHubWebhookEvent>
 */
class GitHubWebhookEventFactory extends Factory
{
    protected $model = GitHubWebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_id' => (string) Str::uuid(),
            'event' => 'issues',
            'action' => 'opened',
            'repository' => 'GrupoRyR/portafolio',
            'payload' => ['action' => 'opened'],
            'status' => GitHubWebhookEvent::STATUS_PROCESSED,
            'received_at' => now(),
        ];
    }
}
