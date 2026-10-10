<?php

namespace App\Models;

use Database\Factories\GitHubWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A signed GitHub webhook delivery, stored as received and processed on the
 * database queue (App\Jobs\ProcessGitHubEvent). Failed events can be queued
 * again from Administración › Integraciones › GitHub.
 *
 * @property int $id
 * @property string $delivery_id
 * @property string $event
 * @property ?string $action
 * @property ?string $repository
 * @property array<string, mixed> $payload
 * @property string $status
 * @property ?string $error
 * @property ?string $error_code
 * @property ?list<string> $request_keys
 * @property Carbon $received_at
 * @property ?Carbon $processed_at
 * @property int $attempts
 */
#[Fillable([
    'delivery_id', 'event', 'action', 'repository', 'payload', 'status', 'error', 'error_code', 'request_keys',
    'received_at', 'processed_at', 'attempts',
])]
class GitHubWebhookEvent extends Model
{
    /** @use HasFactory<GitHubWebhookEventFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_IGNORED = 'ignored';

    /** The event types the app subscribes to (design `WebhookEventType`). */
    public const SUBSCRIBED_EVENTS = ['issues', 'pull_request', 'release', 'workflow_run', 'push'];

    public const ERROR_REPO_NOT_LINKED = 'repo_not_linked';

    protected $table = 'github_webhook_events';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'request_keys' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
