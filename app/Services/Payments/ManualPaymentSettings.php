<?php

namespace App\Services\Payments;

use App\Models\PlatformSetting;
use App\Services\Ads\AdSettings;

/**
 * Admin-editable settings of the manual WhatsApp payment method:
 * config('payments.defaults') overridden by platform_settings "payments.<key>".
 * The minimum top-up is the sponsoring setting ads.min_top_up, so every top-up
 * method shares one minimum.
 */
class ManualPaymentSettings
{
    const PREFIX = 'payments.';

    public function __construct(private AdSettings $ads) {}

    /** @return array{whatsapp_enabled: bool, whatsapp_number: string, max_top_up: float, template_wallet_topup: string, template_plan_upgrade: string, min_top_up: float} */
    public function all(): array
    {
        $saved = PlatformSetting::query()->where('key', 'like', self::PREFIX . '%')->pluck('value', 'key');
        $out = [];
        foreach ($this->defaults() as $key => $default) {
            $out[$key] = $saved[self::PREFIX . $key] ?? $default;
        }
        $out['whatsapp_enabled'] = (bool) $out['whatsapp_enabled'];
        $out['max_top_up']       = round((float) $out['max_top_up'], 3);
        $out['min_top_up']       = $this->ads->float('min_top_up');
        return $out;
    }

    public function get(string $key)
    {
        return $this->all()[$key] ?? null;
    }

    public function enabled(): bool
    {
        return (bool) $this->get('whatsapp_enabled');
    }

    /** Digits only, as wa.me wants them ("21657252576"). */
    public function whatsappDigits(): string
    {
        return WhatsApp::normalizePhone((string) $this->get('whatsapp_number')) ?? '';
    }

    public function set(array $values, ?int $userId = null): void
    {
        if (array_key_exists('min_top_up', $values)) {
            $this->ads->set(['min_top_up' => round((float) $values['min_top_up'], 3)], $userId);
            unset($values['min_top_up']);
        }
        foreach ($values as $key => $value) {
            if (array_key_exists($key, $this->defaults())) {
                PlatformSetting::setValue(self::PREFIX . $key, $value, $userId);
            }
        }
    }

    public function defaults(): array
    {
        return (array) config('payments.defaults', []);
    }
}
