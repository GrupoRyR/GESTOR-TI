<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): Settings
    {
        return app(Settings::class);
    }

    public function test_it_returns_typed_defaults_from_the_config(): void
    {
        config(['gestor.settings_defaults' => [
            'alerts.cooldown_minutes' => 60,
            'ai.monthly_cost_cap_usd' => 0.0,
            'feature.enabled' => false,
        ]]);

        $this->assertSame(60, $this->settings()->get('alerts.cooldown_minutes'));
        $this->assertSame(0.0, $this->settings()->get('ai.monthly_cost_cap_usd'));
        $this->assertFalse($this->settings()->get('feature.enabled'));
    }

    public function test_the_shipped_defaults_include_the_known_keys(): void
    {
        $this->assertSame(60, $this->settings()->get('alerts.cooldown_minutes'));
        $this->assertSame(3, $this->settings()->get('ai.first_response_business_days'));
        $this->assertSame(80, $this->settings()->get('ai.cost_warning_percent'));
    }

    public function test_unknown_keys_fall_back_to_the_given_default(): void
    {
        $this->assertNull($this->settings()->get('missing.key'));
        $this->assertSame('x', $this->settings()->get('missing.key', 'x'));
    }

    public function test_set_persists_json_values_and_overrides_the_default(): void
    {
        $this->assertSame(60, $this->settings()->get('alerts.cooldown_minutes'));

        $this->settings()->set('alerts.cooldown_minutes', 15);
        $this->settings()->set('ai.providers', ['deepseek' => ['model' => 'deepseek-chat']]);

        $this->assertSame(15, $this->settings()->get('alerts.cooldown_minutes'));
        $this->assertSame(['deepseek' => ['model' => 'deepseek-chat']], $this->settings()->get('ai.providers'));
        $this->assertDatabaseHas('settings', ['key' => 'alerts.cooldown_minutes']);
        $this->assertSame(15, Setting::query()->where('key', 'alerts.cooldown_minutes')->value('value'));
    }

    public function test_values_are_cached_and_the_cache_is_refreshed_on_set(): void
    {
        $this->settings()->set('alerts.cooldown_minutes', 15);
        $this->assertSame(15, $this->settings()->get('alerts.cooldown_minutes'));

        // A write that bypasses the service is not seen while cached.
        Setting::query()->where('key', 'alerts.cooldown_minutes')->update(['value' => json_encode(99)]);
        $this->assertSame(15, $this->settings()->get('alerts.cooldown_minutes'));

        $this->settings()->set('alerts.cooldown_minutes', 30);
        $this->assertSame(30, $this->settings()->get('alerts.cooldown_minutes'));
    }

    public function test_forget_restores_the_default(): void
    {
        $this->settings()->set('alerts.cooldown_minutes', 15);
        $this->settings()->forget('alerts.cooldown_minutes');

        $this->assertSame(60, $this->settings()->get('alerts.cooldown_minutes'));
        $this->assertDatabaseMissing('settings', ['key' => 'alerts.cooldown_minutes']);
    }
}
