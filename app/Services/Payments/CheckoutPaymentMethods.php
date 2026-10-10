<?php

namespace App\Services\Payments;

use App\Models\PlatformSetting;
use App\Services\Delivery\DeliverySettings;

/**
 * Which payment methods a customer may use at checkout. Admin switch per
 * method (platform_settings "checkout.payment_methods"); the Stripe / D17 /
 * wallet code stays in place and comes back when its switch is turned on.
 *
 * Launch: cash on delivery only. COD can't be turned off.
 * Disabled = shown as "Coming soon" in the storefront and rejected by the API.
 */
class CheckoutPaymentMethods
{
    public const KEY      = 'checkout.payment_methods';
    public const ALL      = ['cod', 'card', 'd17', 'wallet'];
    public const DEFAULTS = ['cod' => true, 'card' => false, 'd17' => false, 'wallet' => false];

    /** @return array<string, bool> */
    public function all(): array
    {
        $saved = (array) (PlatformSetting::getValue(self::KEY) ?? []);
        $out = [];
        foreach (self::DEFAULTS as $method => $default) {
            $out[$method] = (bool) ($saved[$method] ?? $default);
        }
        $out['cod'] = true;
        return $out;
    }

    public function enabled(string $method): bool
    {
        return $this->all()[$method] ?? false;
    }

    /** @return string[] */
    public function enabledList(): array
    {
        return array_keys(array_filter($this->all()));
    }

    /** @param array<string, bool> $switches */
    public function set(array $switches, ?int $adminId): void
    {
        $current = $this->all();
        $next    = $current;
        foreach (self::ALL as $method) {
            if ($method !== 'cod' && array_key_exists($method, $switches)) {
                $next[$method] = (bool) $switches[$method];
            }
        }

        $changes = [];
        foreach ($next as $method => $on) {
            if ($on !== $current[$method]) {
                $changes[] = ['field' => "payment_method.{$method}", 'old' => $current[$method] ? 'enabled' : 'disabled', 'new' => $on ? 'enabled' : 'disabled'];
            }
        }
        if ($changes) {
            PlatformSetting::setValue(self::KEY, $next, $adminId);
            app(DeliverySettings::class)->log($changes, $adminId);
        }
    }
}
