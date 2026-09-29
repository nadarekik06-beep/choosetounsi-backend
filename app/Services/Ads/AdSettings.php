<?php

namespace App\Services\Ads;

use App\Models\PlatformSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Tunable numbers of the ad engine: config('ads.defaults') overridden by
 * platform_settings rows keyed "ads.<name>". Read everything through here —
 * never config('ads.defaults.*') directly, never duplicated in a frontend.
 *
 *   app(AdSettings::class)->get('min_cpc');                 // 0.2
 *   app(AdSettings::class)->get('tier_click_discount.red'); // 0.15
 */
class AdSettings
{
    const PREFIX    = 'ads.';
    const CACHE_KEY = 'ads:settings:v1';

    private ?array $memo = null;

    /** Every setting, defaults merged with admin overrides. */
    public function all(): array
    {
        return $this->memo ??= Cache::remember(
            self::CACHE_KEY,
            (int) config('ads.settings_cache_seconds', 300),
            fn () => $this->load()
        );
    }

    /** @param string $key  setting name without the "ads." prefix; dot notation reaches into arrays */
    public function get(string $key, $default = null)
    {
        return Arr::get($this->all(), $key, $default);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key, 0);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key, 0);
    }

    /**
     * Save admin overrides. Only known top-level keys are accepted; array
     * settings are replaced as a whole.
     *
     * @param array<string, mixed> $values  name => value (no prefix)
     */
    public function set(array $values, ?int $userId = null): void
    {
        $defaults = $this->defaults();
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                throw new InvalidArgumentException("Unknown ad setting: {$key}");
            }
        }
        foreach ($values as $key => $value) {
            PlatformSetting::setValue(self::PREFIX . $key, $value, $userId);
        }
        $this->flush();
    }

    public function flush(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    public function defaults(): array
    {
        return (array) config('ads.defaults', []);
    }

    private function load(): array
    {
        $settings  = $this->defaults();
        $overrides = PlatformSetting::query()
            ->where('key', 'like', self::PREFIX . '%')
            ->get(['key', 'value']);

        foreach ($overrides as $row) {
            $name = substr($row->key, strlen(self::PREFIX));
            // Stale keys (a setting that was renamed or removed) are ignored.
            if (array_key_exists($name, $settings) && $row->value !== null) {
                $settings[$name] = $row->value;
            }
        }
        return $settings;
    }
}
