<?php

namespace App\Services\Ads\Payments;

use App\Models\AdTopUp;
use App\Services\Ads\AdWalletService;
use Illuminate\Http\Request;

/** Development only: the top-up is paid instantly, no money moves. Enabled by config('ads.gateways.sandbox'). */
class SandboxGateway implements AdTopUpGateway
{
    public function __construct(private AdWalletService $wallets) {}

    public function key(): string
    {
        return 'sandbox';
    }

    public function isAvailable(): bool
    {
        return (bool) config('ads.gateways.sandbox');
    }

    public function start(AdTopUp $topUp, array $input): array
    {
        $this->wallets->settleTopUp($topUp, true, 'sandbox-' . $topUp->id, ['sandbox' => true]);
        return ['status' => AdTopUp::STATUS_PAID];
    }

    public function handleCallback(Request $request): ?array
    {
        return null;
    }
}
