<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Application settings: stored overrides (`settings` table) over the typed
 * defaults of config/gestor.php `settings_defaults`.
 *
 * Keys are flat strings (dots are part of the key, not a path). Values are
 * JSON, so they come back with their JSON type. All overrides are cached in
 * one entry, refreshed on every `set()`/`forget()`.
 */
class Settings
{
    private const CACHE_KEY = 'gestor.settings';

    public function __construct(private readonly Cache $cache) {}

    /**
     * Stored value, else the configured default, else `$default`.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $stored = $this->stored();

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        $defaults = $this->defaults();

        return array_key_exists($key, $defaults) ? $defaults[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Removes the override, so the default applies again.
     */
    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();

        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Defaults merged with the stored overrides.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace($this->defaults(), $this->stored());
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return (array) config('gestor.settings_defaults', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return $this->cache->rememberForever(self::CACHE_KEY, fn (): array => Setting::query()
            ->get(['key', 'value'])
            ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value])
            ->all());
    }
}
