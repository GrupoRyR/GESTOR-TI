<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Models\User;
use App\Support\Integrations\AiProviderSettings;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Responses\Data\TextUsage;
use Throwable;

/**
 * Runs Laravel AI SDK agents against the provider chosen in
 * Administración › Integraciones (PROMPT 6.8.3):
 *
 * - provider and model from App\Support\Integrations\AiProviderSettings;
 *   the key comes only from config/services.php (`.env`), copied into the
 *   SDK's provider config right before the call;
 * - synchronous `prompt()` with a 60 s timeout (no queue, no streaming:
 *   shared hosting); the agent adds one retry on 429/5xx
 *   (App\Services\Ai\RetryTransientStep) and its own step limit;
 * - one `ai_interactions` row per provider call (successful or failed):
 *   provider, model, feature, tokens with and without cache, cost from the
 *   configured prices (App\Services\Ai\AiPricing), duration, status;
 * - the monthly cap: `status()` is `off_no_key` without a key and `off_cap`
 *   once the month's spend reaches the cap (then nothing is sent); after
 *   each run App\Services\Ai\AiCostCap raises the 80 % / 100 % alerts.
 *
 * `run()` never throws: a failure comes back in `AiRun::$error`.
 */
#[Singleton]
class AiRuntime
{
    public const TIMEOUT_SECONDS = 60;

    private ?Agent $current = null;

    /** @var list<AiInteraction> */
    private array $interactions = [];

    private ?User $user = null;

    private string $feature = '';

    private bool $listening = false;

    public function __construct(
        private readonly AiProviderSettings $settings,
        private readonly AiPricing $pricing,
        private readonly AiCostCap $cap,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @return 'ready'|'off_no_key'|'off_cap'
     */
    public function status(): string
    {
        return match ($this->settings->status()) {
            'off' => 'off_no_key',
            'capped' => 'off_cap',
            default => 'ready',
        };
    }

    public function ready(): bool
    {
        return $this->status() === 'ready';
    }

    public function run(Agent $agent, string $prompt, ?User $user, string $feature): AiRun
    {
        $this->listen();

        $this->current = $agent;
        $this->interactions = [];
        $this->user = $user;
        $this->feature = $feature;

        try {
            $response = $agent->prompt($prompt, provider: $this->configureProvider(), model: $this->settings->model(), timeout: self::TIMEOUT_SECONDS);
            $run = new AiRun($response, $this->interactions, null);
        } catch (Throwable $exception) {
            $run = new AiRun(null, $this->interactions, $exception);
        } finally {
            $this->current = null;
            $this->user = null;
        }

        $this->cap->check();

        return $run;
    }

    /**
     * Copies the provider credential (config/services.php) into the SDK
     * config and drops a cached provider instance built with an old key.
     */
    private function configureProvider(): string
    {
        $provider = $this->settings->provider();
        $credential = AiProviderSettings::providers()[$provider]['credential'] ?? null;
        $value = is_string($credential) ? config($credential) : null;

        if ($provider === 'ollama') {
            config(['ai.providers.ollama.url' => $value, 'ai.providers.ollama.key' => '']);
        } else {
            config(["ai.providers.{$provider}.key" => $value]);
        }

        app(AiManager::class)->forgetInstance($provider);

        return $provider;
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        $this->events->listen(StepCompleted::class, function (StepCompleted $event): void {
            if ($this->current !== null && $event->agent === $this->current) {
                $this->record($event->provider->name(), $event->model, $event->response->usage, $event->time, null);
            }
        });

        $this->events->listen(StepFailed::class, function (StepFailed $event): void {
            if ($this->current !== null && $event->agent === $this->current) {
                $this->record($event->provider->name(), $event->model, new TextUsage, $event->time, $event->exception);
            }
        });
    }

    private function record(string $provider, string $model, TextUsage $usage, float $milliseconds, ?Throwable $error): void
    {
        $cached = (int) ($usage->cacheReadInputTokens ?? 0);

        $this->interactions[] = AiInteraction::query()->create([
            'company_id' => $this->user?->company_id,
            'user_id' => $this->user?->id,
            'provider' => mb_substr($provider, 0, 32),
            'model' => mb_substr($model, 0, 64),
            'feature' => $this->feature,
            'input_tokens' => $usage->inputTokens,
            'cached_input_tokens' => $cached,
            'output_tokens' => $usage->outputTokens,
            'cost' => $this->pricing->cost($model, $usage->inputTokens, $cached, $usage->outputTokens),
            'currency' => 'USD',
            'duration_ms' => (int) round($milliseconds),
            'status' => $error === null ? 'ok' : 'error',
            'error' => $error === null ? null : self::describe($error),
        ]);
    }

    /**
     * Class and HTTP status only: provider bodies are never stored.
     */
    public static function describe(Throwable $error): string
    {
        $status = RetryTransientStep::status($error);

        return class_basename($error).($status === null ? '' : " (HTTP {$status})");
    }
}
