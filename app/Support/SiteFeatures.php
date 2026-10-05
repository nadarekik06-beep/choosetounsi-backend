<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Storefront sections the admin can switch on/off, stored as platform_settings
 * rows keyed "features.<name>". Unknown or unset flags fall back to DEFAULTS.
 *
 *   SiteFeatures::enabled('wear_tounsi');   // false until the admin turns it on
 */
class SiteFeatures
{
    const PREFIX    = 'features.';
    const CACHE_KEY = 'site:features:v1';

    /** name => default. WearTounsi stays hidden until the brand launches. */
    const DEFAULTS = [
        'wear_tounsi' => false,
    ];

    private static ?array $memo = null;

    /** @return array<string, bool> */
    public static function all(): array
    {
        return self::$memo ??= Cache::remember(self::CACHE_KEY, 300, function () {
            $flags = self::DEFAULTS;
            $rows  = PlatformSetting::where('key', 'like', self::PREFIX . '%')->get(['key', 'value']);
            foreach ($rows as $row) {
                $name = substr($row->key, strlen(self::PREFIX));
                if (array_key_exists($name, $flags) && $row->value !== null) {
                    $flags[$name] = (bool) $row->value;
                }
            }
            return $flags;
        });
    }

    public static function enabled(string $name): bool
    {
        return self::all()[$name] ?? false;
    }

    /** @param array<string, bool> $flags */
    public static function set(array $flags, ?int $userId = null): void
    {
        foreach ($flags as $name => $_) {
            if (!array_key_exists($name, self::DEFAULTS)) {
                throw new InvalidArgumentException("Unknown feature: {$name}");
            }
        }
        foreach ($flags as $name => $on) {
            PlatformSetting::setValue(self::PREFIX . $name, (bool) $on, $userId);
        }
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }
}
