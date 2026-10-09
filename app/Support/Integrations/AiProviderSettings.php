<?php

namespace App\Support\Integrations;

use App\Models\AiInteraction;
use App\Support\Settings;

/**
 * AI provider, model and monthly spend cap (PROMPT §6.8.3), stored in
 * `Settings` (`ai.provider`, `ai.model`, `ai.monthly_cost_cap_usd`,
 * `ai.cost_warning_percent`) with the catalog of config/gestor.php
 * `ai.providers`. The month is the calendar month in America/Bogota; the
 * spend is the sum of `ai_interactions.cost` (USD) in it.
 */
class AiProviderSettings
{
    public const TIMEZONE = 'America/Bogota';

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array<string, array{label: string, credential: string, models: list<string>}>
     */
    public static function providers(): array
    {
        return (array) config('gestor.ai.providers', []);
    }

    public function provider(): string
    {
        return (string) $this->settings->get('ai.provider', 'deepseek');
    }

    public function model(): string
    {
        return (string) $this->settings->get('ai.model', 'deepseek-chat');
    }

    /**
     * Monthly cap in USD; null when not set (stored 0 or empty).
     */
    public function monthlyCap(): ?float
    {
        $cap = (float) $this->settings->get('ai.monthly_cost_cap_usd', 0);

        return $cap > 0 ? $cap : null;
    }

    public function warnRatio(): float
    {
        return round((float) $this->settings->get('ai.cost_warning_percent', 80) / 100, 4);
    }

    public function monthSpend(): float
    {
        $start = now(self::TIMEZONE)->startOfMonth()->setTimezone((string) config('app.timezone'));

        return round((float) AiInteraction::query()
            ->where('currency', 'USD')
            ->where('created_at', '>=', $start)
            ->sum('cost'), 4);
    }

    /**
     * The provider's credential (API key, Ollama base URL) is set.
     */
    public function isConfigured(?string $provider = null): bool
    {
        $credential = self::providers()[$provider ?? $this->provider()]['credential'] ?? null;

        return is_string($credential) && filled(config($credential));
    }

    /**
     * @return 'ok'|'off'|'capped'
     */
    public function status(): string
    {
        if (! $this->isConfigured()) {
            return 'off';
        }

        $cap = $this->monthlyCap();

        return $cap !== null && $this->monthSpend() >= $cap ? 'capped' : 'ok';
    }

    /**
     * @return array{provider: string, model: string, monthly_cap_usd: ?float}
     */
    public function snapshot(): array
    {
        return ['provider' => $this->provider(), 'model' => $this->model(), 'monthly_cap_usd' => $this->monthlyCap()];
    }

    /**
     * `IntegrationsProps['ai']`. Model options are the curated models of the
     * saved provider, plus the saved model when it is not in that list.
     *
     * @return array<string, mixed>
     */
    public function toProps(): array
    {
        $providers = self::providers();
        $provider = $this->provider();
        $model = $this->model();
        $models = array_values((array) ($providers[$provider]['models'] ?? []));

        if (! in_array($model, $models, true)) {
            array_unshift($models, $model);
        }

        return [
            'status' => $this->status(),
            'provider' => $provider,
            'model' => $model,
            'provider_options' => collect($providers)
                ->map(fn (array $item, string $key): array => ['value' => $key, 'label' => (string) $item['label']])
                ->values()
                ->all(),
            'model_options' => array_map(fn (string $item): array => ['value' => $item, 'label' => $item], $models),
            'monthly_cap_usd' => $this->monthlyCap(),
            'month_spend_usd' => $this->monthSpend(),
            'warn_ratio' => $this->warnRatio(),
        ];
    }
}
