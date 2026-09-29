<?php

namespace App\Services\Ads\Payments;

use App\Exceptions\Ads\AdRuleViolation;

/** Registry of top-up gateways; only available ones can be used. */
class AdTopUpGateways
{
    const ALL = [
        SandboxGateway::class,
        ManualAdminGateway::class,
        KonnectGateway::class,
        FlouciGateway::class,
    ];

    /** @return AdTopUpGateway[] keyed by gateway key */
    public function all(): array
    {
        $out = [];
        foreach (self::ALL as $class) {
            $gateway = app($class);
            $out[$gateway->key()] = $gateway;
        }
        return $out;
    }

    /** @return string[] */
    public function availableKeys(): array
    {
        return array_keys(array_filter($this->all(), fn (AdTopUpGateway $g) => $g->isAvailable()));
    }

    public function get(string $key): AdTopUpGateway
    {
        $gateway = $this->all()[$key] ?? null;
        if (!$gateway || !$gateway->isAvailable()) {
            throw AdRuleViolation::make('gateway_unavailable');
        }
        return $gateway;
    }
}
