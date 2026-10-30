<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Guest checkout switch + the guest cart token.
 *
 * enabled(): the platform_settings override (php artisan guest-checkout on|off)
 * wins over config('guest_checkout.enabled') (GUEST_CHECKOUT_ENABLED).
 */
class GuestCheckout
{
    public const SETTING_KEY  = 'guest_checkout_enabled';
    public const CART_HEADER  = 'X-Cart-Token';
    private const TOKEN_REGEX = '/^[A-Za-z0-9_-]{32,64}$/';

    public static function enabled(): bool
    {
        $override = self::override();
        return $override ?? (bool) config('guest_checkout.enabled');
    }

    /** true / false when set by the command, null when the .env value applies. */
    public static function override(): ?bool
    {
        try {
            $value = PlatformSetting::getValue(self::SETTING_KEY);
        } catch (\Throwable $e) {
            return null; // table missing (fresh install): fall back to config
        }
        return $value === null ? null : (bool) (is_array($value) ? ($value['enabled'] ?? false) : $value);
    }

    /** The guest cart token sent by the storefront, null when absent or malformed. */
    public static function cartToken(Request $request): ?string
    {
        $token = (string) $request->header(self::CART_HEADER, '');
        return preg_match(self::TOKEN_REGEX, $token) ? $token : null;
    }

    /** Random token for the public tracking link (/order/track/{token}). */
    public static function newTrackingToken(): string
    {
        return Str::random(40);
    }

    /** "+216 98 123 456" → "** *** 456": enough for the buyer to recognise it. */
    public static function maskPhone(?string $phone): ?string
    {
        $digits = TunisianPhone::normalize((string) $phone);
        if (!$digits || strlen($digits) < 3) return null;
        return '** *** ' . substr($digits, -3);
    }
}
