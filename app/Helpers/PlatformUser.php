<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * PlatformUser
 *
 * Resolves the platform user ID (the "seller" account that owns all
 * CHOOSE'Tounsi brand products) from cache → settings table → config file.
 *
 * Usage:
 *   $id = \App\Helpers\PlatformUser::id();
 *
 * Or via the facade helper in controllers/models:
 *   use App\Helpers\PlatformUser;
 *   PlatformUser::id()   // returns int
 *   PlatformUser::isPlatform($sellerId)  // returns bool
 */
class PlatformUser
{
    private static ?int $cachedId = null;

    /**
     * Get the platform user's ID.
     * Cached in memory for the duration of the request.
     */
    public static function id(): ?int
    {
        if (static::$cachedId !== null) {
            return static::$cachedId;
        }

        // 1. Try settings table
        if (DB::getSchemaBuilder()->hasTable('settings')) {
            $setting = DB::table('settings')->where('key', 'platform_user_id')->first();
            if ($setting) {
                static::$cachedId = (int) $setting->value;
                return static::$cachedId;
            }
        }

        // 2. Try config file (written by PlatformUserSeeder as fallback)
        if (file_exists(config_path('platform.php'))) {
            $cfg = require config_path('platform.php');
            if (!empty($cfg['platform_user_id'])) {
                static::$cachedId = (int) $cfg['platform_user_id'];
                return static::$cachedId;
            }
        }

        // 3. Try finding by email directly
        $user = DB::table('users')->where('email', 'platform@choosetounsi.tn')->first();
        if ($user) {
            static::$cachedId = (int) $user->id;
            return static::$cachedId;
        }

        return null;
    }

    /**
     * Check if a given seller_id belongs to the platform (Choosetounsi itself).
     */
    public static function isPlatform(?int $sellerId): bool
    {
        if ($sellerId === null) return false;
        return $sellerId === static::id();
    }

    /**
     * Reset the cached ID (useful in tests).
     */
    public static function reset(): void
    {
        static::$cachedId = null;
    }
}